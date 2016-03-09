<?php

namespace app\controllers\api;

use Yii;
use app\models\Friends;
use app\models\Person;
use app\models\Invite;
use app\models\MobileDevices;
use app\models\PersonBlock;
use app\models\PersonImage;

class FriendController extends ApiController {

    public function actionAddFriend() {
        $request = Yii::$app->request->post();
        $person = $this->checkPersonAuthByToken($request['token']);
        $friendData = json_decode($request['data']);
        if (!$friendData->friend_id) {
            $this->sendResponse(400);
        }
        if ($this->isFriend($person->id, $friendData->friend_id) == 1) {
            $this->sendResponse(200, false, null, 'You already are friends');
        }
        if ($follower = $this->isFollower($person->id, $friendData->friend_id)) {
            $follower->is_follower_higher = 0;
            $follower->is_follower_lower = 0;
            $follower->update(true);
            $this->sendResponse(200, true, [], 'Friend');
        } else if ($follower = $this->isFollower($friendData->friend_id, $person->id)) {
            $this->sendResponse(200, false, null, 'You already are follower');
        } else {
            if ($this->isInvite($person->id, $friendData->friend_id) == 1) {
                $this->sendResponse(200, false, null, 'You already sent request to this person');
            } else if ($inviteData = Invite::findOne(['from_id' => $friendData->friend_id, 'to_id' => $person->id])) {
                $friend = new Friends();
                if ($person->id < $friendData->friend_id) {
                    $friend->lower_id = $person->id;
                    $friend->higher_id = $friendData->friend_id;
                } else {
                    $friend->higher_id = $person->id;
                    $friend->lower_id = $friendData->friend_id;
                }
                if ($friendData->accepted == 1) {
                    $friend->is_follower_lower = 0;
                    $friend->is_follower_higher = 0;
                    if (!$friend->validate()) {
                        $this->sendResponse(400);
                    }
                    if ($friend->save()) {
                        $inviteData->delete();
                    } else {
                        $this->sendResponse(501);
                    }
                    //TODO push notifications
                    //$badges = $this->getPersonBadges($person->person_id);
                    //$this->sendResponse(200, true, ['login' => $person->login, 'badges' => $badges], 'Request accepted');
                    $this->sendResponse(200, true, [], 'Friend');
                } else {
                    if ($person->id < $friendData->friend_id) {
                        $friend->is_follower_lower = 0;
                        $friend->is_follower_higher = 1;
                    } else {
                        $friend->is_follower_higher = 0;
                        $friend->is_follower_lower = 1;
                    }
                    if (!$friend->validate()) {
                        $this->sendResponse(400);
                    }
                    if ($friend->save()) {
                        $inviteData->delete();
                    } else {
                        $this->sendResponse(501);
                    }
                    $inviteData->delete();
                    //TODO push notifications
                    //$badges = $this->getPersonBadges($person->person_id);
                    //$this->sendResponse(200, true, ['login' => $person->login, 'badges' => $badges], 'Request denied');
                    $this->sendResponse(200, true, [], 'Follower');
                }
            } else {
                $time = time();
                $currentTime = date('Y-m-d H:i:s', $time);
                $invite = new Invite();
                $invite->from_id = $person->id;
                $invite->to_id = $friendData->friend_id;
                $invite->date = $currentTime;
                if (!$invite->validate()) {
                    $this->sendResponse(400);
                }
                if (!$invite->save()) {
                    $this->sendResponse(501);
                }
                //send push
                $creator = Person::find()
                    ->where('id = :id',['id' => $friendData->friend_id])
                    ->one();
                if($creator) {
                    if($creator->personPush->friend_request) {
                        $tokenData = MobileDevices::find()->where('email = :email',['email' =>$creator->email])->all();
                        if($tokenData) {
                            $message = "You have a new invite.";
                            $apns = Yii::$app->apns;
                            $apns->send($tokenData, $message,
                                [
                                    'sound' => 'default',
                                    'badge' => 4,
                                    'person_id' => $friendData->friend_id,
                                    'type' => 'friend_request'
                                ]
                            );
                            
                        }
                    }
                }
                $this->sendResponse(200, true, [], 'Invite');

                //TODO  push notifications
                /*
                  $personInvited = Person::find()->where('person_id = :id',['id' =>$request['friend_id']])->one();
                  $tokenData = MobileDevices::find()->where('login = :login',['login' =>$personInvited->login])->all();

                  if($tokenData){
                  $tokens = array();
                  foreach($tokenData as $token){
                  $tokens[] = $token->device_token;
                  }
                  $fname = $person->fname;
                  $person->lname?$lname =  $person->lname: $lname = "";
                  $message = $fname." ".$lname.' хочет добваить вас в друзья';

                  $badges = $this->getPersonBadges($request['friend_id']);

                  // @var $apns \bryglen\apnsgcm\Apns
                  $apns = Yii::$app->apns;
                  $apns->sendMulti($tokens, $message,
                  [
                  'person_id' => $person->person_id,
                  'type' => 'friend'
                  ],
                  [
                  'sound' => 'default',
                  'badge' => $badges,
                  ]
                  );
                  }
                  $badges = $this->getPersonBadges($person->person_id);
                  $this->sendResponse(200, true, ['login' => $person->login, 'badges' => $badges], 'Request send');
                 * 
                 */
            }
        }
    }

    public function actionGetFriendsList() {
        $request = Yii::$app->request->post();
        $person = $this->checkPersonAuthByToken($request['token']);
        $inboxInvite = null;
        $friendData = json_decode($request['data']);
        if (!$friendData->person_id) {
            $friendsData = Friends::find()->where('(lower_id = ' . $person->id . ' OR higher_id = ' . $person->id . ') AND (is_follower_lower = 0 AND is_follower_higher = 0)')->all();
            $inboxInvite = Invite::find()->where('to_id = ' . $person->id)->with('from')->all();
        } else {
            if (Person::findOne(['id' => $friendData->person_id]) && $this->isBlock($friendData->person_id, $person->id) == 0) {
                $friendsData = Friends::find()->where('(lower_id = ' . $friendData->person_id . ' OR higher_id = ' . $friendData->person_id . ') AND (is_follower_lower = 0 AND is_follower_higher = 0)')->all();
            }
        }
        $friendList = [];
        $inviteList = [];
        if ($friendsData) {
            foreach ($friendsData as $friend) {
                $friendData->person_id ? $personId = $friendData->person_id : $personId = $person->id;
                if ($friend->lower_id != $personId) {
                    $friendId = $friend->lower_id;
                } else {
                    $friendId = $friend->higher_id;
                }
                $friendPerson = Person::findOne(['id' => $friendId]);
                $params = [':id' => $friendId];
                $avatar = PersonImage::find()->where('person_id = :id', $params)->one();
                $friendList [] = [
                    'id' => $friendId,
                    'username' => $friendPerson->username,
                    'is_banned' => $friendPerson->is_banned,
                    'image' => isset($avatar) ? Yii::$app->params['avatarPath'].$avatar->img_blob : ''
                ];
            }
        }
        if ($inboxInvite) {
            foreach ($inboxInvite as $invite) {
                $params = [':id' => $invite->id];
                $avatar = PersonImage::find()->where('person_id = :id', $params)->one();
                $inviteList [] = [
                    'id' => $invite->from->id,
                    'username' => $invite->from->username,
                    'is_banned' => $invite->from->is_banned,
                    'image' => isset($avatar) ? Yii::$app->params['avatarPath'].$avatar->img_blob : ''
                ];
            }
        }
        $this->sendResponse(200, true, ['friend_list' => $friendList, 'invite_list' => $inviteList], 'Ok');
    }

    public function actionDeleteFriend() {
        $request = Yii::$app->request->post();
        $person = $this->checkPersonAuthByToken($request['token']);
        $friendData = json_decode($request['data']);
        if (!$friendData) {
            $this->sendResponse(400);
        }
        $friend = Friends::find()->where('(lower_id = ' . $person->id . ' AND higher_id = ' . $friendData->friend_id .
                        ') OR (higher_id = ' . $person->id . ' AND lower_id = ' . $friendData->friend_id . ')')->one();
        if ($friend) {
            if ($person->id < $friendData->friend_id) {
                $friend->is_follower_lower = 0;
                $friend->is_follower_higher = 1;
            } else {
                $friend->is_follower_higher = 0;
                $friend->is_follower_lower = 1;
            }
            $friend->update(true);
        } else {
            $this->sendResponse(200, false, null, 'Friend not found');
        }
        $this->sendResponse(200, true, [], 'Ok');
    }

    public function actionGetFollowerList() {
        $request = Yii::$app->request->post();
        $person = $this->checkPersonAuthByToken($request['token']);
        $personData = json_decode($request['data']);
        $personId = $personData->person_id ? $personData->person_id : $person->id;
        $params = [
            ':person_id' => $personId
        ];
        $followerList = [];
        if ($personData->person_id) {
            if (!$user = Person::findOne(['id' => $personData->person_id])) {
                $this->sendResponse(404);
            }
            if (($user->is_private == 1 || $this->isFriend($person->id, $personData->person_id) == 0) && $this->isBlock($person->id, $personData->person_id) == 1) {
                $this->sendResponse(200, false, [], 'Access denied');
            }
        }
        $followerDataList = Friends::find()->where('(lower_id = :person_id AND is_follower_higher = 1) OR (higher_id = :person_id AND is_follower_lower = 1)', $params)->all();
        foreach ($followerDataList as $followerData) {
            if ($followerData->lower_id == $personId) {
                $followerId = $followerData->higher_id;
            } else {
                $followerId = $followerData->lower_id;
            }
            $follower = Person::findOne(['id' => $followerId]);
            $params = [':id' => $followerId];
            $avatar = PersonImage::find()->where('person_id = :id', $params)->one();
            $followerList [] = [
                'id' => $followerId,
                'username' => $follower->username,
                'is_banned' => $follower->is_banned,
                'image' => isset($avatar) ? Yii::$app->params['avatarPath'].$avatar->img_blob : ''
            ];
        }
        $this->sendResponse(200, true, $followerList, 'Ok');
    }

}
