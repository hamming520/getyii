<?php
/**
 * author     : forecho <caizhenghai@gmail.com>
 * createTime : 15/4/20 下午9:17
 * description:
 */

?>
<div class="panel panel-default">
    <div class="panel-heading clearfix">
        评论文章 <?php if (Yii::$app->user->getIsGuest()): ?> <small class="text-warning">(需要登录)</small> <?php endif ?>
    </div>

    <?= $this->render('_form', [
        'model' => $model,
    ]) ?>
</div>