<?php

namespace app\controllers\api;

use app\models\Person;
use app\models\Stream;
use app\models\StreamPhoto;
use app\models\PhotoLike;
use Yii;

class LikeController extends ApiController {

    public function actionAddLike() {
        $request = Yii::$app->request->post();
        $person = $this->checkPersonAuthByToken($request['token']);
        $likeData = json_decode($request['data']);
        if ($likeData->photo_id && $likeData->stream_photo) {
            $time = time();
            $currentTime = date('Y-m-d H:i:s', $time);
            $timer = StreamPhoto::find()->where(['id' => $likeData->photo_id])->with('stream')->one();
            $data = [];
            if ($timer && $timer->stream->is_active == 1) {
                $like = new PhotoLike();
                $like->person_id = $person->id;
                $like->photo_id = $likeData->photo_id;
                $like->date = $currentTime;
                if ($like->validate()) {
                    if (!$like->save()) {
                        $this->sendResponse(501);
                    }                    
                } else {
                    $this->sendResponse(400);
                }
                foreach ($likeData->stream_photo as $photo) {
                    $likes = $this->likeCount($photo) ? $this->likeCount($photo) : 0;
                    $data [] = [
                        'image_id' => $photo,
                        'likes' => $likes
                    ];
                }
            } else {
                foreach ($likeData->stream_photo as $photo) {
                    $likes = $this->likeCount($photo) ? $this->likeCount($photo) : 0;
                    $data [] = [
                        'image_id' => $photo,
                        'likes' => $likes
                    ];
                }
                $this->sendResponse(200, false, $data, 'Stream is not active');
            }
            //send push
            if($timer->stream->creator_id) {
                $creator = Person::find()
                    ->where('id = :id',['id' => $timer->stream->creator_id])
                    ->one();
                if($creator) {
                    if($creator->personPush->every_like) {
                        $tokenData = MobileDevices::find()->where('email = :email',['email' =>$creator->email])->all();
                        if($tokenData) {
                            $message = "Your stream was liked.";
                            $apns = Yii::$app->apns;
                            $apns->send($tokenData, $message,
                                [
                                    'sound' => 'default',
                                    'badge' => 3,
                                    'person_id' => $timer->stream->id,
                                    'type' => 'like_stream'
                                ]
                            );
                        }
                    }
                }
            }
            $this->sendResponse(200, true, $data, 'Ok');
        } else {
            $this->sendResponse(400);
        }
    }

    public function actionUnlike() {
        $request = Yii::$app->request->post();
        $person = $this->checkPersonAuthByToken($request['token']);
        $likeData = json_decode($request['data']);
        if ($likeData->photo_id) {
            $like = PhotoLike::findOne(['photo_id' => $likeData->photo_id, 'person_id' => $person->id]);
            if ($like) {
                $like->delete();
            } else {
                $this->sendResponse(404);
            }
            $this->sendResponse(200, true, [], 'Ok');
        } else {
            $this->sendResponse(400);
        }
    }

}
