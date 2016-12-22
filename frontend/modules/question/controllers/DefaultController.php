<?php

namespace frontend\modules\question\controllers;

use common\models\Post;
use common\models\Search;
use common\models\SearchLog;
use common\models\User;
use common\services\NotificationService;
use common\services\TopicService;
use frontend\modules\question\models\Question;
use frontend\modules\user\models\UserMeta;
use Yii;
use yii\filters\AccessControl;
use common\models\PostSearch;
use common\models\PostComment;
use common\models\PostMeta;
use common\models\UserInfo;
use common\components\Controller;
use yii\helpers\ArrayHelper;
use yii\helpers\Url;
use yii\web\NotFoundHttpException;
use yii\filters\VerbFilter;
use yii\data\ActiveDataProvider;
use yii\helpers\Html;
use frontend\modules\user\models\Donate;

class DefaultController extends Controller
{
    const PAGE_SIZE = 50;
    public $sorts = [
        'newest' => '最新的',
        'excellent' => '优质主题',
        'hotest' => '热门的',
        'uncommented' => '未回答的'
    ];

    public function behaviors()
    {
        return ArrayHelper::merge(parent::behaviors(), [
            'verbs' => [
                'class' => VerbFilter::className(),
                'actions' => [
                    'delete' => ['post'],
                ],
            ],
            'access' => [
                'class' => AccessControl::className(),
                'rules' => [
                    // 默认只能Get方式访问
                    ['allow' => true, 'actions' => ['view', 'index', 'search'], 'verbs' => ['GET']],
                    // 登录用户才能提交评论或其他内容
                    ['allow' => true, 'actions' => ['api', 'view', 'delete'], 'verbs' => ['POST'], 'roles' => ['@']],
                    // 登录用户才能使用API操作(赞,踩,收藏)
                    ['allow' => true, 'actions' => ['create', 'update', 'revoke', 'excellent','top','recommend'], 'roles' => ['@']],
                ]
            ],
        ]);
    }

    /**
     * 话题列表
     * @return mixed
     */
    public function actionIndex()
    {
        $searchModel = new PostSearch();

        // 问题或者分类筛选
        $params = Yii::$app->request->queryParams;
        empty($params['tag']) ?: $params['PostSearch']['tags'] = $params['tag'];
        if (isset($params['node'])) {
            $postMeta = PostMeta::findOne(['alias' => $params['node']]);
            ($postMeta) ? $params['PostSearch']['post_meta_id'] = $postMeta->id : '';
        }
        //  获取热门节点
        if (isset($params['node'])) {
            $nodeParent = PostMeta::findOne(['alias' => $params['node']])->parent;
            $nodes = PostMeta::find()->where(['not',['parent' =>null]])->andWhere(['parent'=>$nodeParent])->orderBy([ 'count' => SORT_DESC,'order' => SORT_ASC,])->limit(10)->all();
        }
        else{
            $nodes = PostMeta::find()->where(['not',['parent' =>null]])->orderBy([ 'count' => SORT_DESC,'order' => SORT_ASC,])->limit(10)->all();
        }

        $dataProvider = $searchModel->search($params);
        $dataProvider->query->andWhere([Post::tableName() . '.type' => 'question', 'status' => [Post::STATUS_ACTIVE, Post::STATUS_EXCELLENT]]);
        $dataProvider->pagination->pageSize=20;
        // 排序
        $sort = $dataProvider->getSort();
        $sort->attributes = array_merge($sort->attributes, [
            'hotest' => [
                'asc' => [
                    'comment_count' => SORT_DESC,
                    'created_at' => SORT_DESC
                ],
            ],
            'excellent' => [
                'asc' => [
                    'status' => SORT_DESC,
                    'comment_count' => SORT_DESC,
                    'created_at' => SORT_DESC
                ],
            ],
            'uncommented' => [
                'asc' => [
                    'comment_count' => SORT_ASC,
                    'created_at' => SORT_DESC
                ],
            ]
        ]);

        return $this->render('index', [
            'searchModel' => $searchModel,
            'nodes' => $nodes,
            'sorts' => $this->sorts,
            'dataProvider' => $dataProvider,
        ]);
    }

    public function actionSearch()
    {
        $searchModel = new Search();
        $keyword = Yii::$app->request->get('keyword');
        if (empty($keyword)) $this->goHome();

        // 记录log
        $model = new SearchLog();
        $model->setAttributes([
            'user_id' => (Yii::$app->user->isGuest) ? '' : Yii::$app->user->identity->getId(),
            'keyword' => $keyword,
            'created_at' => time(),
        ]);
        $model->save();

        $dataProvider = $searchModel->search($keyword);

        return $this->render('search', [
            'searchModel' => $searchModel,
            'dataProvider' => $dataProvider,
        ]);
    }

    /**
     * 话题详细页
     * @param integer $id
     * @return mixed
     */
    public function actionView($id)
    {
        $model = Question::findQuestion($id);

        //登录才能访问的节点内容
        $MetaId = $model->post_meta_id;
        $node = PostMeta::findOne(['id'=>$MetaId]);
        if (\Yii::$app->user->isGuest && in_array($node->alias, params('LoginNode')) ) {
            return $this->redirect('/login');
        }

        $dataProvider = new ActiveDataProvider([
            'query' => PostComment::findCommentList($id),
            'pagination' => [
                'pageSize' => self::PAGE_SIZE,
            ],
            'sort' => ['defaultOrder' => ['created_at' => SORT_ASC]]
        ]);

        // 更新浏览次数
        Question::updateAllCounters(['view_count' => 1], ['id' => $id]);

        $user = Yii::$app->user->identity;
        $admin = ($user && ($user->isAdmin($user->username) || $user->isSuperAdmin($user->username))) ? true : false;

        return $this->render('view', [
            'model' => $model,
            'dataProvider' => $dataProvider,
            'comment' => new PostComment(),
            'admin' => $admin,
        ]);
    }

    /**
     * 新建话题
     * @return mixed
     */
    public function actionCreate()
    {
        $model = new Question();
        if ($model->load(Yii::$app->request->post()) && $model->validate()) {
            $topService = new TopicService();
            if (!$topService->filterContent($model->title) || !$topService->filterContent($model->content)) {
                $this->flash('请勿发表无意义的内容', 'warning');
                return $this->redirect('create');
            }

            if ($model->save()) {
                $this->flash('发表文章成功!', 'success');
                return $this->redirect(['view', 'id' => $model->id]);
            }

        } else {
            /*获取当前节点*/
            $params  = Yii::$app->request->get();
            $meta_id = isset($params['meta_id']) ? trim($params['meta_id']) : 0;
            $model->post_meta_id = $meta_id;
            //print_r($model);exit;
            return $this->render('create', [
                'model' => $model,
            ]);
        }
    }

    /**
     * 修改自己的话题
     * @param integer $id
     * @return string|\yii\web\Response
     * @throws NotFoundHttpException
     */
    public function actionUpdate($id)
    {
        $model = Question::findQuestion($id);

        if (!($model && (User::getThrones() || $model->isCurrent()))) {
            throw new NotFoundHttpException;
        }

        if ($model->load(Yii::$app->request->post()) && $model->validate()) {
            if ($model->save()) {
                $this->flash('发表更新成功!', 'success');
                return $this->redirect(['view', 'id' => $model->id]);
            }
        } else {
            return $this->render('update', [
                'model' => $model,
            ]);
        }
    }

    /**
     * 伪删除
     * @param $id
     * @return \yii\web\Response
     * @throws NotFoundHttpException
     */
    public function actionDelete($id)
    {
        $model = Question::findTopic($id);
        if (!($model && (User::getThrones() || $model->isCurrent()))) {
            throw new NotFoundHttpException;
        }

        if ($model->comment_count) {
            $this->flash("「{$model->title}」此文章已有评论，属于共有财产，不能删除", 'warning');
        } else {

            TopicService::delete($model);
            $revoke = Html::a('撤消', ['/topic/default/revoke', 'id' => $model->id]);
            $this->flash("「{$model->title}」文章删除成功。 反悔了？{$revoke}", 'success');
        }

        return $this->redirect(['index']);
    }

    /**
     * 撤消删除
     * @param $id
     * @return \yii\web\Response
     * @throws NotFoundHttpException
     */
    public function actionRevoke($id)
    {
        $model = Question::findDeletedTopic($id);
        if (!($model && (User::getThrones() || $model->isCurrent()))) {
            throw new NotFoundHttpException;
        }
        TopicService::revoke($model);
        $this->flash("「{$model->title}」文章撤销删除成功。", 'success');
        return $this->redirect(['/topic/default/view', 'id' => $model->id]);
    }

    /**
     * 加精华
     * @param $id
     * @return \yii\web\Response
     * @throws NotFoundHttpException
     */
    public function actionExcellent($id)
    {
        $user = Yii::$app->user->identity;
        $model = Question::findTopic($id);
        if ($user && ($user->isAdmin($user->username) || $user->isSuperAdmin($user->username))) {
            TopicService::excellent($model);
            $this->flash("操作成功", 'success');
            return $this->redirect(['/topic/default/view', 'id' => $model->id]);
        } else {
            throw new NotFoundHttpException();
        }
    }

    /**
     * 加推荐
     * @param $id
     * @return \yii\web\Response
     * @throws NotFoundHttpException
     */
    public function actionRecommend($id)
    {
        $user = Yii::$app->user->identity;
        $model = Question::findTopic($id);
        if ($user && ($user->isAdmin($user->username) || $user->isSuperAdmin($user->username))) {
            TopicService::recommend($model);
            $this->flash("操作成功", 'success');
            return $this->redirect(['/topic/default/view', 'id' => $model->id]);
        } else {
            throw new NotFoundHttpException();
        }
    }

    /**
     * 加置顶
     * @param $id
     * @return \yii\web\Response
     * @throws NotFoundHttpException
     */
    public function actionTop($id)
    {
        $user = Yii::$app->user->identity;
        $model = Question::findTopic($id);
        if ($user && ($user->isAdmin($user->username) || $user->isSuperAdmin($user->username))) {
            TopicService::top($model);
            $this->flash("操作成功", 'success');
            return $this->redirect(['/topic/default/view', 'id' => $model->id]);
        } else {
            throw new NotFoundHttpException();
        }
    }
}
