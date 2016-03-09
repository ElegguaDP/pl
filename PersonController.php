<?php

/*
 * To change this license header, choose License Headers in Project Properties.
 * To change this template file, choose Tools | Templates
 * and open the template in the editor.
 */

namespace app\controllers\api;

use app\models\MobileDevices;
use app\models\PersonPush;
use Yii;
use app\models\Person;
use app\models\PersonElastic;
use app\models\Token;
use app\models\Region;
use app\models\Invite;
use app\models\PersonImage;
use app\models\Friends;
use app\models\Stream;
use app\models\PersonBlock;
use yii\helpers\Url;

class PersonController extends ApiController
{
    /*
     * person authorisation
     */

    public function actionAuthentication()
    {
        $request = Yii::$app->request->post();
        $personData = json_decode($request['data']);
        $person = Person::find()->where('email = :email', [':email' => $personData->email])->with('token')->one();
        $token = '';
        $exp_date = '';
        $is_confirmed = false;

        if (!$person || !($person->validatePassword($personData->password))) {
            $this->sendResponse(200, false, null, 'incorrect login or password');
        } else {
            if ($person->token) {
                $token = $person->token->token;
                $exp_date = $person->token->exp_date;
            } else {
                //create Person Token
                $personToken = new Token();
                $personToken->person_id = $person->id;
                $personToken->token = Person::generateAccessToken();
                $personToken->exp_date = date(DATE_W3C, (time() + Person::EXPIRATION_DATE));
                if (!$personToken->validate()) {
                    $this->sendResponse(200, FALSE, NULL, $personToken->getErrors());
                    Yii::$app->end();
                }
                $personToken->save();
                $token = $personToken->token;
                $exp_date = $personToken->exp_date;
            }

            $data = array(
                'token' => $token,
                'exp_date' => $exp_date,
                'is_banned' => $person->is_banned,
                'person_id' => $person->id,
                'is_confirmed' => $person->is_confirmed,
                'region_id' => $person->region_id
            );

            if ($personData->device_token) {
                $deviceToken = $personData->device_token;
                $device = 0;
                $device = MobileDevices::find()->where('device_token = :token', ['token' => $personData->device_token])->one();

                if (!$device) {
                    $newToken = new MobileDevices();
                    $newToken->device_token = $deviceToken;
                    $newToken->email = $person->email;
                    $newToken->save();
                } else {
                    $device->email = $personData->email;
                    $device->validate();
                    $device->save();
                }
            }

            $this->sendResponse(200, true, $data, 'Ok');
        }
    }

    /*
     * get person data
     */

    public function actionGetPerson()
    {
        $request = Yii::$app->request->post();
        $person = $this->checkPersonAuthByToken($request['token']);
        if ($request['data']) {
            $personData = json_decode($request['data']);
        } else {
            $this->sendResponse(400);
        }

        if (!$personData->person_id) {
            $data = [
                'username' => $person->username,
                'name' => $person->name ? $person->name : '',
                'email' => $person->email ? $person->email : '',
                'gender' => $person->gender,
                'bdate' => $person->bdate,
                'region_id' => $person->region_id,
                'status' => $person->status ? base64_decode($person->status) : '',
                'friends_count' => Friends::getFriendCount($person->id) ? Friends::getFriendCount($person->id) : '0',
                'followers_count' => Friends::getFollowersCount($person->id) ? Friends::getFollowersCount($person->id) : '0',
                'streams_count' => Stream::getStreamsCount($person->id) ? Stream::getStreamsCount($person->id) : '0',
                'invites_count' => Invite::getInvitesCount($person->id) ? Invite::getInvitesCount($person->id) : '0',
                'is_private' => $person->is_private
            ];
            $region = Region::findOne($person->region_id);
            $avatar = PersonImage::find()->where('person_id = :id', [':id' => $person->id])->one();
            isset($region) ? $data['region'] = $region->name : $data['region'] = "";
            isset($avatar) ? $data['image'] = Yii::$app->params['avatarPath'].$avatar->img_blob : $data['image'] = "";

        } else {
            $blokPerson = $this->isBlockPerson($personData->person_id, $person->id);
            if ($blokPerson == 1) {
                $this->sendResponse(403);
            }
            $personRequest = Person::findOne(['id' => $personData->person_id]);
            if ($personRequest) {
                $params = [
                    ':person_id' => $personData->person_id,
                    ':user_id' => $person->id
                ];
                $isInvite = $this->isInvite($personData->person_id, $person->id);
                $isBlocked = $this->isBlock($person->id, $personData->person_id);
                $data = [
                    'username' => $personRequest->username,
                    'status' => $personRequest->status ? base64_decode($personRequest->status) : '',
                    'friends_count' => Friends::getFriendCount($personRequest->id) ? Friends::getFriendCount($personRequest->id) : '0',
                    'followers_count' => Friends::getFollowersCount($personRequest->id) ? Friends::getFollowersCount($personRequest->id) : '0',
                    'streams_count' => Stream::getStreamsCount($personRequest->id) ? Stream::getStreamsCount($personRequest->id) : '0',
                    'is_private' => $personRequest->is_private,
                    'is_friend' => $this->isFriend($person->id, $personData->person_id),
                    'is_invite' => $isInvite,
                    'is_blocked' => $isBlocked
                ];
                $region = Region::findOne($personRequest->region_id);
                $avatar = PersonImage::find()->where('person_id = :id', [':id' => $personRequest->id])->one();
                isset($avatar) ? $data['image'] = Yii::$app->params['avatarPath'].$avatar->img_blob : "";

                if ($personRequest->is_private != 1 || $this->isFriend($person->id, $personData->person_id)) {
                    $data['bdate'] = $personRequest->bdate;
                    $data['gender'] = $personRequest->gender;
                    isset($region) ? $data['region'] = $region->name : "";
                }
            } else {
                $this->sendResponse(404);
            }
        }

        $this->sendResponse(200, true, $data, 'Ok');
    }

    /*
     * person registration
     */

    public function actionCreatePersonToken($person_id)
    {
        $personToken = new Token();
        $personToken->token = Person::generateAccessToken();
        $personToken->exp_date = date(DATE_W3C, (time() + Person::EXPIRATION_DATE));
        $personToken->person_id = $person_id;
        $personToken->save();
    }


    public function actionRegistration()
    {
        $errors = array();
        $request = Yii::$app->request->post();
        $data = [];
        $personData = json_decode($request['data']);
        if (isset($personData->service) && $personData->token) {
            $personData->service == 'fb' ? $check = Person::find()->where(['fb_token' => $personData->token])->one() : $check = Person::find()->where(['tw_token' => $personData->token])->with('token')->one();
            if (!$check) {
                if ($personData->email) {
                    $personData->email ? $checkEmail = Person::find()->where(['email' => $personData->email])->with('token')->one() : $checkEmail = Person::find()->where(['email' => $personData->email])->with('token')->one();
                    if ($checkEmail) {
                        $personData->service == 'fb' ? $checkEmail->fb_token = $personData->token : $checkEmail->tw_token = $personData->token;
                        if ($checkEmail->validate() && $checkEmail->save()) {
                            $personPush = new PersonPush();
                            $personPush->person_id = $checkEmail->id;
                            $personPush->save();

                            $data = array(
                                'token' => $checkEmail->token->token,
                                'exp_date' => $checkEmail->token->exp_date,
                                'person_id' => $checkEmail->id,
                                'is_confirmed' => $checkEmail->is_confirmed?$checkEmail->is_confirmed:'0',
                                'is_banned' => $checkEmail->is_banned?$checkEmail->is_banned:'0'
                            );
                        }
                    } else {
                        $person = new Person();
                        $personData->service == 'fb' ? $person->fb_token = $personData->token : $person->tw_token = $personData->token;
                        $person->email = $personData->email;

                        if ($person->validate() && $person->save()) {
                            $this->actionCreatePersonToken($person->id);
                            $personPush = new PersonPush();
                            $personPush->person_id = $person->id;
                            $personPush->save();
                            $data = array(
                                'token' => $person->token->token,
                                'exp_date' => $person->token->exp_date,
                                'person_id' => $person->id,
                                'is_confirmed' => $person->is_confirmed?$person->is_confirmed:'0',
                                'is_banned' => $person->is_banned?$person->is_banned:'0'
                            );
                        }
                    }
                } else {
                    $person = new Person();
                    $personData->service == 'fb' ? $person->fb_token = $personData->token : $person->tw_token = $personData->token;

                    if ($person->validate() && $person->save()) {
                        $this->actionCreatePersonToken($person->id);
                        $personPush = new PersonPush();
                        $personPush->person_id = $person->id;
                        $personPush->save();
                        $data = array(
                            'token' => $person->token->token,
                            'exp_date' => $person->token->exp_date,
                            'person_id' => $person->id,
                            'is_confirmed' => $person->is_confirmed?$person->is_confirmed:'0',
                            'is_banned' => $person->is_banned?$person->is_banned:'0'
                        );
                    }
                }
            } else {
                if (!$check->is_banned) {
                    if ($personData->email) {
                        if ($check->email == $personData->email) {
                            $personPush = new PersonPush();
                            $personPush->person_id = $check->id;
                            $personPush->save();
                            $data = array(
                                'token' => $check->token->token,
                                'exp_date' => $check->token->exp_date,
                                'person_id' => $check->id,
                                'is_confirmed' => $check->is_confirmed ? $check->is_confirmed : '0',
                            );
                        } else {
                            $personData->email ? $checkEmail = Person::find()->where(['email' => $personData->email])->with('token')->one() : $checkEmail = Person::find()->where(['email' => $personData->email])->with('token')->one();
                            if ($checkEmail) {
                                $this->sendResponse(200, false, [], 'Error. Person with this e-mail and token already exist. Please, login with email.');
                            } else {
                                $personPush = new PersonPush();
                                $personPush->person_id = $check->id;
                                $personPush->save();
                                $data = array(
                                    'token' => $check->token->token,
                                    'exp_date' => $check->token->exp_date,
                                    'person_id' => $check->id,
                                    'is_confirmed' => $check->is_confirmed ? $check->is_confirmed : '0',
                                );
                            }
                        }
                    } else {
                        $personPush = new PersonPush();
                        $personPush->person_id = $check->id;
                        $personPush->save();
                        $data = array(
                            'token' => $check->token->token,
                            'exp_date' => $check->token->exp_date,
                            'person_id' => $check->id,
                            'is_confirmed' => $check->is_confirmed ? $check->is_confirmed : '0',
                        );
                    }
                } else {
                    $this->sendResponse(403);
                }
            }
        } else {
            $person = new Person();
            $person->email = $personData->email;

            $person->setPassword($personData->password);

            if ($person->validate() && $person->save()) {
                $this->actionCreatePersonToken($person->id);
                $personPush = new PersonPush();
                $personPush->person_id = $person->id;
                $personPush->save();
                $data = array(
                    'token' => $person->token->token,
                    'exp_date' => $person->token->exp_date,
                    'person_id' => $person->id,
                    'is_confirmed' => '0',
                );
            }

        }

        //check errors
        isset($person) ? $errors = array_merge($person->getErrors()) : '';
        isset($check) ? $errors = array_merge($check->getErrors()) : '';
        isset($checkEmail) ? $errors = array_merge($checkEmail->getErrors()) : '';

        if (empty($errors)) {
            $this->sendResponse(200, true, $data, 'Ok');
        } else {
            $errorMessage = '';
            foreach ($errors as $error) {
                $errorMessage .= $error[0];
            }
            $this->sendResponse(200, false, null, $errorMessage);
        }
    }

    /*
     * restore forgotten password
     */

    public function actionRestorePassword()
    {
        $request = Yii::$app->request->post();

        //find user by email
        $personAuth = Person::findOne(['email' => $request['email']]);

        if (!$personAuth) {
            $this->sendResponse(200, false, NULL, 'This email does not exist or wrong');
            Yii::$app->end();
        }
        //create password restore hash and hash expiration time        
        $currentTime = time();
        $personHash = md5($request['email'] . $currentTime);
        $hashExpirationDate = $currentTime + Person::HASH_LIFE_TIME;
        $personAuth->person_hash = $personHash;
        $personAuth->hash_exp_date = $hashExpirationDate;
        if ($personAuth->save()) {
            //create restore URL     
            $urlParams = array(
                'email' => $personAuth->email,
                'personHash' => $personHash
            );
            $restoreUrl = Url::to(['api/restore-password/generate-password', $urlParams], true);

            Yii::$app->mailer->compose()
                ->setFrom("admin@picklook.com")
                ->setTo($request['email'])
                ->setSubject("Password Restore")
                ->setTextBody("Для восстановления ваших учетных данных Picklook просто перейдите по этой ссылке:" . "\r\n"
                    . $restoreUrl . "\r\n"
                    . "Обратите внимание, что срок действия ссылки истекает через 24 часа." . "\r\n"
                    . "По истечении этого срока ссылка станет недействительной и запрос на изменение учетных данных придется повторить."
                )
                ->send();
            $this->sendResponse(200, true, array('email' => $request['email'], 'note' => 'Restore link send to youre email.'), 'Ok');
        } else {
            $this->sendResponse(200, false, NULL, 'Restore link is not create, plese try againe');
            Yii::$app->end();
        }
    }

    /*
     * update person data
     */
    public function actionUpdatePerson()
    {
        $request = Yii::$app->request->post();
        $personAuth = $this->checkPersonAuthByToken($request['token']);
        $personData = json_decode($request['data']);
        $errorMessage = '';

        if ($personData->username && $personData->gender && $personData->region_id) {
            //check username uniq
            if (Person::find()->where('username = :username and id != :user_id', [':username' => $personData->username, ':user_id' => $personAuth->id])->one()) {
                $this->sendResponse(200, false, null, 'This username already used.');
            }
            $person = Person::findOne(['id' => $personAuth['id']]);
            $person->username = $personData->username;
            $person->gender = $personData->gender;
            $person->region_id = $personData->region_id;

            $personData->name ? $person->name = $personData->name : $person->name = '';
            //$personData->bdate ? $person->bdate = $personData->bdate : $person->bdate = '';
            if(isset($personData->bdate)) {
                 $person->bdate = $personData->bdate;
            }
            $personData->status ? $person->status = base64_encode($personData->status) : $person->status = '';
            if(isset($personData->email)) {
                $person->email = $personData->email;
            }
            //$personData->email ? $person->email = $personData->email : $person->email = '';

            $person->is_confirmed = 1;
           
            if ($person->validate()) {
                if (!$person->save()) {
                    $this->sendResponse(501);
                }

                $personElasticCheck = PersonElastic::find()->where(['id' => $person->id])->one();

                if ($personElasticCheck) {
                    $personElasticCheck->attributes = ['id' => $person->id, 'name' => $person->name, 'username' => $person->username];
                    $personElasticCheck->update();
                } else {
                    $personElastic = new PersonElastic();

                    $personElastic->attributes = ['id' => $person->id, 'name' => $person->name, 'username' => $person->username];

                    $personElastic->save();
                }

                $this->sendResponse(200, true, array('person_id' => $person->id), 'Ok');
            } else {
                $this->sendResponse(400);
                Yii::$app->end();
            }
        } else {
            $this->sendResponse(400);
        }
    }

    public function actionUpdatePassword()
    {
        $request = Yii::$app->request->post();
        $personAuth = $this->checkPersonAuthByToken($request['token']);
        $personData = json_decode($request['data']);
        $errorMessage = '';

        if ($personData->oldPassword && $personData->newPassword) {
            if (isset($personAuth->password)) {
                $personAuth->validatePassword($personData->oldPassword) ? $personAuth->setPassword($personData->newPassword) : $this->sendResponse(200, false, NULL, 'Incorrect password');
            }
            $personAuth->setPassword($personData->newPassword);

            if ($personAuth->validate() && $personAuth->save()) {
                $this->sendResponse(200, true, array('person_id' => $personAuth->id), 'Ok');
            } else {
                foreach ($personAuth->getErrors() as $error) {
                    $errorMessage .= $error[0] . " ";
                }
                $this->sendResponse(200, false, null, $errorMessage);
                Yii::$app->end();
            }
        } else {
            $this->sendResponse(200, false, NULL, 'Incorrect person data');
        }
    }

    public function actionUploadAvatar()
    {
        $request = Yii::$app->request->post();
        $personAuth = $this->checkPersonAuthByToken($request['token']);
        $personData = json_decode($request['data']);
        if ($personData->img_blob) {
            $imageData = PersonImage::findOne(['person_id' => $personAuth->id]);
            $filename = Yii::$app->basePath . "/uploads/avatar/";  
            $imageFile = base64_decode($personData->img_blob);
            $f = finfo_open();
            $mime_type = finfo_buffer($f, $imageFile, FILEINFO_MIME_TYPE);
            $ext = $this->type_to_ext($mime_type);
            $name = Yii::$app->getSecurity()->generateRandomString() . $ext;
            $file = $filename . $name;
            file_put_contents($file, $imageFile);
            if (!$imageData) {
                $image = new PersonImage();
                $image->img_blob = $name;
                $image->person_id = $personAuth->id;
                if ($image->validate() && $image->save()) {
                    $this->sendResponse(200, true, array('email' => $personAuth->email), 'Ok');
                } else {
                    $this->sendResponse(200, false, NULL, 'incorrect image data');
                }
            } else {
                $imageData->img_blob = $name;
                if ($imageData->validate() && $imageData->save()) {
                    $this->sendResponse(200, true, array('email' => $personAuth->email), 'Ok');
                } else {
                    $this->sendResponse(200, false, NULL, 'incorrect image data');
                }
            }
        }
    }
    
    public function type_to_ext($type)
    {
        switch ($type) {
            case 'image/gif':
                $extension = '.gif';
                break;
            case 'image/jpeg':
                $extension = '.jpg';
                break;
            case 'image/png':
                $extension = '.png';
                break;
            default:
                // handle errors
                break;
        }
        return $extension;
    }

    public function actionNewExpirationDate()
    {
        $request = Yii::$app->request->post();
        $personAuth = $this->checkPersonAuthByToken($request['token']);
        $personData = json_decode($request['data']);
        if ($personData->expiration_date) {
            if (strtotime($personAuth->expiration_date) < time()) {
                $personAuth->expiration_date = date(DATE_W3C, (time() + ($personData->expiration_date * 24 * 60 * 60)));
            } else {
                $personAuth->expiration_date = date(DATE_W3C, strtotime($personAuth->expiration_date) + ($personData->expiration_date * 24 * 60 * 60));
            }

            if ($personAuth->validate() && $personAuth->save()) {
                $this->sendResponse(200, true, ['expiration_date' => strtotime($personAuth->expiration_date)], 'New expiration date save');
            } else {
                $this->sendResponse(200, false, NULL, 'Incorrect expiration date');
            }
        }

        $this->sendResponse(200, false, NULL, 'No data');
    }

    public function actionLogout()
    {
        $request = Yii::$app->request->post();
        $personAuth = $this->checkPersonAuthByToken($request['token']);
        $personData = json_decode($request['data']);
        if ($personData->device_token) {
            //MobileDevices::deleteAll('device_token = :token', ['token' => $personData->device_token]);
            $this->sendResponse(200, true, [], 'Logout');
        }

        $this->sendResponse(200, true, [], 'No token');
    }

    public function actionBlockPerson()
    {
        $request = Yii::$app->request->post();
        $person = $this->checkPersonAuthByToken($request['token']);
        $personData = json_decode($request['data']);
        if ($personData->block_id) {
            $block = new PersonBlock();
            $block->from_id = $person->id;
            $block->to_id = $personData->block_id;
            if ($block->validate()) {
                if (!$block->save()) {
                    $this->sendResponse(501);
                }
            } else {
                $this->sendResponse(400);
            }
            $this->sendResponse(200, true, [], 'Ok');
        } else {
            $this->sendResponse(400);
        }
    }

    public function actionGetBlockList()
    {
        $request = Yii::$app->request->post();
        $person = $this->checkPersonAuthByToken($request['token']);
        $blockList = PersonBlock::findAll(['from_id' => $person->id]);
        $blockPersonList = [];
        if ($blockList) {
            foreach ($blockList as $block) {
                $blockPerson = Person::findOne(['id' => $block->to_id]);
                $params = [':id' => $blockPerson->id];
                $avatar = PersonImage::find()->where('person_id = :id', $params)->one();
                $blockPersonList [] = [
                    'id' => $blockPerson->id,
                    'username' => $blockPerson->username,
                    'image' => isset($avatar) ? Yii::$app->params['avatarPath'].$avatar->img_blob : ''
                ];
            }
        }
        $this->sendResponse(200, true, $blockPersonList, 'Ok');
    }

    public function actionUnblockPerson()
    {
        $request = Yii::$app->request->post();
        $person = $this->checkPersonAuthByToken($request['token']);
        $personData = json_decode($request['data']);
        if ($personData->block_id) {
            $blockPerson = PersonBlock::findOne(['from_id' => $person->id, 'to_id' => $personData->block_id]);
            if ($blockPerson) {
                $blockPerson->delete();
                $this->sendResponse(200, true, [], 'Ok');
            } else {
                $this->sendResponse(404);
            }
        } else {
            $this->sendResponse(400);
        }
    }

    public function actionChangePrivacy()
    {
        $request = Yii::$app->request->post();
        $person = $this->checkPersonAuthByToken($request['token']);
        $personData = json_decode($request['data']);
        if ($personData->private) {
            $person->is_private = 1;
        } else {
            $person->is_private = 0;
        }

        if ($person->validate()) {
            if (!$person->save()) {
                $this->sendResponse(501);
            }
            $this->sendResponse(200, true, [], 'Ok');
        } else {
            $this->sendResponse(400);
        }
    }

    public function actionPushSettings()
    {
        $request = Yii::$app->request->post();
        $person = $this->checkPersonAuthByToken($request['token']);
        $personData = json_decode($request['data']);

        if (!$personData) {
            $this->sendResponse(400);
        }

        $newSettings = PersonPush::find()->where(['person_id' => $person->id])->one();

        if ($newSettings) {
            $newSettings->friends_photo = $personData->friends_photo;
            $newSettings->votes_result = $personData->votes_result;
            $newSettings->every_like = $personData->every_like;
            $newSettings->friend_request = $personData->friend_request;
            $newSettings->new_comment = $personData->new_comment;
            $newSettings->new_mention = $personData->new_mention;
        } else {
            $newSettings = new PersonPush();
            $newSettings->person_id = $person->id;
            $newSettings->friends_photo = $personData->friends_photo;
            $newSettings->votes_result = $personData->votes_result;
            $newSettings->every_like = $personData->every_like;
            $newSettings->friend_request = $personData->friend_request;
            $newSettings->new_comment = $personData->new_comment;
            $newSettings->new_mention = $personData->new_mention;
        }

        if ($newSettings->validate()) {
            if (!$newSettings->save()) {
                $this->sendResponse(501);
            }
            $this->sendResponse(200, true, [], 'Ok');
        } else {
            $this->sendResponse(400);
        }

    }

    public function actionGetPushSettings(){
        $request = Yii::$app->request->post();
        $person = $this->checkPersonAuthByToken($request['token']);

        $settings = PersonPush::find()->where(['person_id' => $person->id])->one();

        if(!$settings){
            $settings = new PersonPush();
            $settings->person_id = $person->id;
            $settings->friends_photo = 1;
            $settings->votes_result = 1;
            $settings->every_like = 1;
            $settings->friend_request = 1;
            $settings->new_comment = 1;
            $settings->new_mention = 1;
            $settings->save();
        }

        $data = [
            'id' => $settings->id,
            'person_id' => $settings->person_id,
            'friends_photo' => $settings->friends_photo,
            'votes_result' => $settings->votes_result,
            'every_like' => $settings->every_like,
            'friend_request' => $settings->friend_request,
            'new_comment' => $settings->new_comment,
            'new_mention' => $settings->new_mention,

        ];

        $this->sendResponse(200, true, $data, 'Ok');
    }
}
