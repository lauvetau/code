<?php

namespace app\modules\admin\controllers;

use Yii;
use yii\helpers\StringHelper;

use app\models\User;

class UserController extends _BaseController
{
    public function actionIndex()
    {
        $active_tab = User::getActiveTab();

        return $this->render('index',[
            'active_tab' => $active_tab,
            'other_tabs' => User::getOtherTabs(),
            'data_provider' => User::getDataProvider($active_tab),
        ]);
    }

    public function actionCreate()
    {   
        $model = new User;

        if ($model->load(Yii::$app->request->post())) {
            $model->generatePassword(5);
            if ($model->save()) {
                return $this->redirect(['index']);
            }
        }

        return $this->render('create', [
            'model' => $model,
        ]);
    }

    public function actionUpdate($id)
    {
        $model = User::findOne($id);

        if ($model->load(Yii::$app->request->post()) && $model->save()) {
            return $this->redirect(['index']);
        }

        return $this->render('update', [
            'model' => $model,
        ]);
    }

    public function actionChangeActive($id)
    {
        $user = User::findOne($id);

        if (in_array($user->id, User::getForbiddenId())) {
            return json_encode([
                'status' => 'error', 
                'message' => 'Нельзя изменить активность своего аккаунта',
            ]);
        } 

        $user->active = (int)!$user->active;
        $user->update(true, ['active']);
        $user_active = $user->active == User::ACTIVE ? 'активирован' : 'деактивирован';

        return json_encode([
            'status' => 'ok', 
            'message' => "Пользователь успешно {$user_active}",
        ]);
    }

    public function actionCover($id)
    {   
        $cover_user = User::getCover($id);
        Yii::$app->session->set('real_user_id', Yii::$app->user->id);
        Yii::$app->user->switchIdentity($cover_user);

        return $this->goHome();
    }

    public function actionTabSwitch($new_tab)
    {
        $active_tab = User::getActiveTab($new_tab);
        $session = Yii::$app->session;
        $admin_stored_tab = $session['admin_stored_tab'];
        $admin_stored_tab[StringHelper::basename(User::class)] = $new_tab;
        $session['admin_stored_tab'] = $admin_stored_tab;

        return $this->renderAjax('index', [
            'active_tab' => $active_tab,
            'other_tabs' => User::getOtherTabs(),
            'data_provider' => User::getDataProvider($active_tab),
        ]);
    }
}
