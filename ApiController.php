<?php

namespace app\controllers\api;

use Yii;
use yii\filters\VerbFilter;
use yii\filters\ContentNegotiator;
use yii\web\Response;
use app\models\Token;
use app\models\Person;
use app\models\Invite;
use app\models\Friends;
use app\models\PersonBlock;
use app\models\PhotoLike;

class ApiController extends \yii\rest\Controller {

    const SYNC_TIME = 3600;

    public function actionIndex() {
        return 'it works';
    }

    public function behaviors() {
        return [
            'verbs' => [
                'class' => VerbFilter::className(),
                'actions' => [
                    'registration' => ['post'],
                ],
            ],
            'contentNegotiator' => [
                'class' => ContentNegotiator::className(),
                'formats' => [
                    'application/json' => Response::FORMAT_JSON,
                ],
            ],
        ];
    }

    public function actions() {
        $actions = parent::actions();
        /* $actions['creategroup'] = [
          'class' => 'app\controllers\api\GroupController',
          ]; */
        return $actions;
    }

    protected function setHeader($status) {
        $status_header = 'HTTP/1.1 ' . $status . ' ' . $this->_getStatusCodeMessage($status);
        $content_type = "application/json; charset=utf-8";

        header($status_header);
        header('Content-type: ' . $content_type);
        header('X-Powered-By: ' . "Nintriva <nintriva.com>");
    }

    protected function _getStatusCodeMessage($status) {
        $codes = Array(
            200 => 'OK',
            204 => 'No Content',
            400 => 'Bad Request',
            401 => 'Unauthorized',
            402 => 'Payment Required',
            403 => 'Forbidden',
            404 => 'Not Found',
            500 => 'Internal Server Error',
            501 => 'Not Implemented',
        );
        return (isset($codes[$status])) ? $codes[$status] : '';
    }

    protected function sendResponse($code, $statusCode = null, $data = null, $message = null) {
        if (is_null($message) || $message == '') {
            $message = $this->_getStatusCodeMessage($code);
        }

        $this->setHeader($code);
        echo json_encode(array('message' => $message, 'status' => $statusCode, 'data' => $data), JSON_PRETTY_PRINT);
        exit;
    }

    protected function checkPersonAuthByToken($token) {
        //find person token
        $personToken = Token::findOne(['token' => $token]);

        if (!$personToken || is_null($personToken)) {
            $this->sendResponse(401);
            Yii::$app->end();
        }
        //find person
        $person = $personToken->person;

        if (!$person || is_null($person)) {
            $this->sendResponse(404);
            Yii::$app->end();
        } elseif ($person->is_banned) {
            $this->sendResponse(403);
            Yii::$app->end();
        }

        return $person;
    }

    public function isFriend($from_id, $to_id) {
        $params = [
            ':from_id' => $from_id,
            ':to_id' => $to_id
        ];
        $friends = Friends::find()->where('((lower_id = :from_id AND higher_id = :to_id) OR (lower_id = :to_id AND higher_id = :from_id)) AND is_follower_lower = 0 AND is_follower_higher = 0', $params)->one();
        if ($friends) {
            return 1;
        } else {
            return 0;
        }
    }

    public function isInvite($from_id, $to_id) {
        $params = [
            ':from_id' => $from_id,
            ':to_id' => $to_id
        ];
        $isInvite = Invite::find()->where('((from_id = :from_id AND to_id = :to_id))', $params)->one();
        if ($isInvite) {
            return 1;
        } else {
            return 0;
        }
    }

    public function isBlockPerson($from_id, $to_id) {
        $params = [
            ':from_id' => $from_id,
            ':to_id' => $to_id
        ];
        $isBlock = PersonBlock::find()->where('(from_id = :from_id AND to_id = :to_id)', $params)->one();
        if ($isBlock) {
            return 1;
        } else {
            return 0;
        }
    }
    
    public function isBlock($from_id, $to_id) {
        $params = [
            ':from_id' => $from_id,
            ':to_id' => $to_id
        ];
        $isBlock = PersonBlock::find()->where('((from_id = :from_id AND to_id = :to_id) OR (from_id = :to_id AND to_id = :from_id))', $params)->one();
        if ($isBlock) {
            return 1;
        } else {
            return 0;
        }
    }

    public function isFollower($from_id, $to_id) {
        $params = [
            ':from_id' => $from_id,
            ':to_id' => $to_id
        ];
        $follower = Friends::find()->where('(lower_id = :from_id AND higher_id = :to_id AND is_follower_higher = 1) OR (higher_id = :from_id AND lower_id = :to_id AND is_follower_lower = 1)', $params)->one();
        if ($follower) {
            return $follower;
        } else {
            return false;
        }
    }

    public function isLiked($photo_id, $person_id) {
        $like = PhotoLike::findOne(['photo_id' => $photo_id, 'person_id' => $person_id]);
        if ($like) {
            return 1;
        } else {
            return 0;
        }
    }

    public function likeCount($photo_id) {
        $params = [
            ':photo_id' => $photo_id
        ];
        $likeCount = PhotoLike::find()->where('photo_id = :photo_id', $params)->count();
        return $likeCount;
    }

}
