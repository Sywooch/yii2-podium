<?php

/**
 * Podium Module
 * Yii 2 Forum Module
 */
namespace bizley\podium\controllers;

use bizley\podium\behaviors\FlashBehavior;
use bizley\podium\components\Cache;
use bizley\podium\components\Config;
use bizley\podium\models\User;
use bizley\podium\models\UserSearch;
use Exception;
use Yii;
use yii\data\ActiveDataProvider;
use yii\filters\AccessControl;
use yii\helpers\Json;
use yii\web\Controller;

/**
 * Podium Members controller
 * All actions concerning forum members.
 * 
 * @author Paweł Bizley Brzozowski <pb@human-device.com>
 * @since 0.1
 */
class MembersController extends Controller
{

    /**
     * @inheritdoc
     */
    public function behaviors()
    {
        return [
            'access' => [
                'class'        => AccessControl::className(),
                'denyCallback' => function () {
                    return $this->redirect(['account/login']);
                },
                'rules'  => [
                    [
                        'allow'         => false,
                        'matchCallback' => function () {
                            return !$this->module->getInstalled();
                        },
                        'denyCallback' => function () {
                            return $this->redirect(['install/run']);
                        }
                    ],
                    [
                        'allow' => true,
                        'roles' => Config::getInstance()->get('members_visible') ? ['@', '?'] : ['@'],
                    ],
                ],
            ],
            'flash' => FlashBehavior::className(),
        ];
    }

    /**
     * Listing the active users for ajax.
     * @return string|\yii\web\Response
     */
    public function actionFieldlist()
    {
        if (Yii::$app->request->isAjax) {
            $request = Yii::$app->request;
            $results = ['data' => [], 'page' => 1, 'total' => 0];
            $query   = $request->post('query');
            $page    = $request->post('page', 1);

            $currentPage = 0;
            if (!empty($page) && is_numeric($page) && $page > 0) {
                $currentPage = $page - 1;
            }

            $query = preg_replace('/[^\p{L}\w]/', '', $query);

            $cache = Cache::getInstance()->get('members.fieldlist');
            if ($cache === false || empty($cache[$query . '-' . $currentPage])) {
                if ($cache === false) {
                    $cache = [];
                }

                if (empty($query)) {
                    $queryObject = User::find()->where(['status' => User::STATUS_ACTIVE])->orderBy('username, id');
                }
                else {
                    $queryObject = User::find()->where(['and', ['status' => User::STATUS_ACTIVE], ['or', ['like', 'username', $query], ['username' => null]]])->orderBy('username, id');
                }        
                $provider = new ActiveDataProvider([
                    'query' => $queryObject,
                    'pagination' => [
                        'pageSize' => 10,
                    ],
                ]);

                $provider->getPagination()->setPage($currentPage);

                foreach ($provider->getModels() as $data) {
                    $results['data'][] = [
                        'id'    => $data->id,
                        'mark'  => 0,
                        'value' => $data->getPodiumTag(true),
                    ];
                }

                $results['page']  = $provider->getPagination()->getPage() + 1;
                $results['total'] = $provider->getPagination()->getPageCount();

                $cache[$query . '-' . $currentPage] = Json::encode($results);
                Cache::getInstance()->set('members.fieldlist', $cache);
            }

            return $cache[$query . '-' . $currentPage];
        }
        else {
            return $this->redirect(['default/index']);
        }
    }
    
    /**
     * Ignoring the user of given ID.
     * @return \yii\web\Response
     */
    public function actionIgnore($id = null)
    {
        if (!Yii::$app->user->isGuest) {
            try {
                $model = User::findOne(['and', ['id' => (int)$id], ['!=', 'status', User::STATUS_REGISTERED]]);

                if (empty($model)) {
                    $this->error('Sorry! We can not find Member with this ID.');
                }
                elseif ($model->id == Yii::$app->user->id) {
                    $this->error('Sorry! You can not ignore your own account.');
                }
                elseif ($model->id == User::ROLE_ADMIN) {
                    $this->error('Sorry! You can not ignore Administrator.');
                }
                else {

                    if ($model->isIgnoredBy(Yii::$app->user->id)) {

                        Yii::$app->db->createCommand()->delete('{{%podium_user_ignore}}', 'user_id = :uid AND ignored_id = :iid', [':uid' => Yii::$app->user->id, ':iid' => $model->id])->execute();
                        $this->success('User has been unignored.');                    
                    }
                    else {
                        Yii::$app->db->createCommand()->insert('{{%podium_user_ignore}}', ['user_id' => Yii::$app->user->id, 'ignored_id' => $model->id])->execute();
                        $this->success('User has been ignored.');
                    }
                }
            }
            catch (Exception $e) {
                $this->error('Sorry! There was some error while performing this action.');
                Yii::trace([$e->getName(), $e->getMessage()], __METHOD__);
            }
        }
        
        return $this->redirect(['index']);
    }

    /**
     * Listing the users.
     * @return string
     */
    public function actionIndex()
    {
        $searchModel  = new UserSearch;
        $dataProvider = $searchModel->search(Yii::$app->request->get(), true);

        return $this->render('index', [
                    'dataProvider' => $dataProvider,
                    'searchModel'  => $searchModel
        ]);
    }
    
    /**
     * Listing the moderation team.
     * @return string
     */    
    public function actionMods()
    {
        $searchModel  = new UserSearch();
        $dataProvider = $searchModel->search(Yii::$app->request->get(), true, true);

        return $this->render('mods', [
                    'dataProvider' => $dataProvider,
                    'searchModel'  => $searchModel
        ]);
    }
    
    /**
     * Listing posts created by user of given ID.
     * @return string|\yii\web\Response
     */
    public function actionPosts($id = null, $slug = null)
    {
        if (!is_numeric($id) || $id < 1 || empty($slug)) {
            $this->error('Sorry! We can not find the user you are looking for.');
            return $this->redirect(['index']);
        }

        $user = User::findOne(['id' => (int)$id, 'slug' => $slug]);
        if (!$user) {
            $this->error('Sorry! We can not find the user you are looking for.');
            return $this->redirect(['index']);
        }
        else {

            return $this->render('posts', [
                        'user' => $user,
            ]);
        }
    }
    
    /**
     * Listing threads started by user of given ID.
     * @return string|\yii\web\Response
     */
    public function actionThreads($id = null, $slug = null)
    {
        if (!is_numeric($id) || $id < 1 || empty($slug)) {
            $this->error('Sorry! We can not find the user you are looking for.');
            return $this->redirect(['index']);
        }

        $user = User::findOne(['id' => (int)$id, 'slug' => $slug]);
        if (!$user) {
            $this->error('Sorry! We can not find the user you are looking for.');
            return $this->redirect(['index']);
        }
        else {

            return $this->render('threads', [
                        'user' => $user,
            ]);
        }
    }
    
    /**
     * Viewing user profile.
     * @return string|\yii\web\Response
     */
    public function actionView($id = null)
    {
        $model = User::findOne(['and', ['id' => (int)$id], ['!=', 'status', User::STATUS_REGISTERED]]);
        
        if (empty($model)) {
            $this->error('Sorry! We can not find Member with this ID.');
            return $this->redirect(['index']);
        }
        
        return $this->render('view', [
            'model' => $model
        ]);
    }
}                