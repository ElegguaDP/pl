<?php

namespace app\controllers\api;

use Yii;
use app\models\Comment;
use app\models\Person;
use app\models\Stream;
use app\models\Report;
use app\models\PersonImage;


class CommentController extends ApiController {

    public function actionCreateComment() {
        $request = Yii::$app->request->post();
        $person = $this->checkPersonAuthByToken($request['token']);
        $commentData = json_decode($request['data']);
        $time = time();
        $currentTime = date('Y-m-d H:i:s', $time);
        if ($commentData->stream_id && $commentData->text) {
            $comment = new Comment();
            $comment->person_id = $person->id;
            $comment->text = base64_encode($commentData->text);
            $comment->stream_id = $commentData->stream_id;
            $comment->date = $currentTime;
            if ($commentData->parent_com_id) {
                $comment->parent_com_id = $commentData->parent_com_id;
            }
            if ($comment->validate()) {
                if (!$comment->save()) {
                    $this->sendResponse(501);
                }
                $data = [
                    'comment_id' => $comment->id,
                    'text' => base64_decode($comment->text),
                    'username' => $person->username,
                ];
                if ($commentData->parent_com_id) {

                    //send push to parent
                    $commentParrent = Comment::find($commentData->parent_com_id);
                    if($streams) {
                        $creator = Person::find()
                            ->where('id = :id',['id' => $commentParrent->person_id])
                            ->one();
                        if($creator) {
                            if($creator->personPush->new_mention) {
                                $tokenData = MobileDevices::find()->where('email = :email',['email' =>$creator->email])->all();
                                if($tokenData) {
                                    $message = "Your have a new answer for your comment.";
                                    $apns = Yii::$app->apns;
                                    $apns->send($tokenData, $message,
                                        [
                                            'sound' => 'default',
                                            'badge' => 6,
                                            'person_id' => $commentData->stream_id,
                                            'type' => 'new_mention'
                                        ]
                                    );
                                }
                            }
                        }
                    }
                } 
                //send push to creator
                $streams = Stream::find($commentData->stream_id);
                if($streams) {
                    $creator = Person::find()
                        ->where('id = :id',['id' => $streams->creator_id])
                        ->one();
                    if($creator) {
                        if($creator->personPush->new_comment) {
                            $tokenData = MobileDevices::find()->where('email = :email',['email' =>$creator->email])->all();
                            if($tokenData) {
                                $message = "Your stream was commended.";
                                $apns = Yii::$app->apns;
                                $apns->send($tokenData, $message,
                                    [
                                        'sound' => 'default',
                                        'badge' => 5,
                                        'person_id' => $commentData->stream_id,
                                        'type' => 'new_comment'
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
        } else {
            $this->sendResponse(400);
        }
    }

    public function actionGetCommentList() {
        $request = Yii::$app->request->post();
        $person = $this->checkPersonAuthByToken($request['token']);
        $commentData = json_decode($request['data']);
        if (!$commentData->stream_id) {
            $this->sendResponse(400);
        }
        $commentListData = Comment::findAll(['stream_id' => $commentData->stream_id]);
        $commentList = [];
        if ($commentListData) {
            foreach ($commentListData as $comment) {
                $personName = Person::findOne(['id' => $comment->person_id])->username;
                $parentName = '';
                $parentAvatar = '';
                if ($comment->parent_com_id) {
                    $parentPerson = Comment::findOne(['id' => $comment->parent_com_id]);
                    if ($parentPerson) {
                        $parentPersonId = $parentPerson->person_id;
                        $parentName = Person::findOne(['id' => $parentPersonId])->username;
                        $avatarDataParent = PersonImage::find()->where('person_id = :id', [':id' => $parentPersonId])->one();
                        $parentAvatar = $avatarDataParent ? Yii::$app->params['avatarPath'].$avatarDataParent->img_blob : '';
                    }
                }
                $commentAvatarData = PersonImage::find()->where('person_id = :id', [':id' => $comment->person_id])->one();
                $personAvatar = $commentAvatarData ? Yii::$app->params['avatarPath'].$commentAvatarData->img_blob : '';
                $commentList[] = [
                    'comment_id' => $comment->id,
                    'text' => base64_decode($comment->text),
                    'person_id' => $comment->person_id,
                    'person_name' => $personName,
                    'person_image' => $personAvatar,
                    'parent_com_id' => ($comment->parent_com_id && $parentName) ? $comment->parent_com_id : '',
                    'parent_com_name' => $parentName ? $parentName : '',
                    'parent_com_image' => $parentAvatar,
                    'send_date' => $comment->date
                ];
            }
        }
        $this->sendResponse(200, true, $commentList, 'Ok');
    }

    public function actionDeleteComment() {
        $request = Yii::$app->request->post();
        $person = $this->checkPersonAuthByToken($request['token']);
        $commentData = json_decode($request['data']);
        if (!$commentData->comment_id) {
            $this->sendResponse(400);
        }
        Comment::deleteAll(['id' => $commentData->comment_id]);
        $this->sendResponse(200, true, [], 'Ok');
    }

}
