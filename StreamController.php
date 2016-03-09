<?php

namespace app\controllers\api;

use app\models\Comment;
use app\models\Friends;
use app\models\PersonImage;
use Yii;
use app\models\Person;
use app\models\Stream;
use app\models\StreamPhoto;
use app\models\Themes;
use app\models\StreamTimer;
use app\models\StreamElastic;
use app\models\LifeTime;
use app\models\PersonBlock;
use yii\web\UploadedFile;
use app\models\MobileDevices;

class StreamController extends ApiController
{

    public function actionGetThemes()
    {
        $request = Yii::$app->request->post();
        $person = $this->checkPersonAuthByToken($request['token']);
        $themes = Themes::find()->all();
        foreach ($themes as $theme) {
            $themesData [] = [
                'theme_id' => $theme->id,
                'theme_name' => $theme->name,
                'theme_icon' => base64_encode($theme->icon)
            ];
        }
        $this->sendResponse(200, true, $themesData, 'Ok');
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

    public function actionCreateStream()
    {

        $request = Yii::$app->request->post();
        $person = $this->checkPersonAuthByToken($request['token']);
        $streamData = json_decode($request['data']);
        $time = time();
        $currentTime = date('Y-m-d H:i:s', $time);
        if ($streamData->themes_id && $streamData->place_name && $streamData->region_id && count($streamData->images) != 0) {
            $errorMessage = '';
            //set error flag
            $errorFlag = false;
            $errorFlagImage = false;
            //begin transaction
            $transaction = Yii::$app->db->beginTransaction();
            try {
                //create new stream
                $stream = new Stream();
                $stream->creator_id = $person->id;
                $stream->create_date = $currentTime;
                $stream->themes_id = $streamData->themes_id;
                $stream->region_id = $streamData->region_id;
                $stream->description = base64_encode($streamData->description);
                $stream->place_name = base64_encode($streamData->place_name);
                if ($streamData->is_private) {
                    $stream->is_private = $streamData->is_private;
                }

                $connection = Yii::$app->db;
                $command = $connection->createCommand('CALL hw_max_nid');
                $max = $command->queryAll();

                if (is_null($max[0]['maximum'])) {
                    $stream->nid = 0;
                } else {
                    $stream->nid = $max[0]['maximum'] + 1;
                }

                if ($stream->validate()) {
                    if (!$stream->save()) {
                        $errorFlag = true;
                        foreach ($stream->getErrors() as $error) {
                            $errorMessage .= $error[0];
                        }
                    }
                    $imageCount = 0;
                    foreach ($streamData->images as $image) {
                        //add new photo to DB

                        $streamPhoto = new StreamPhoto();

                        $filename = Yii::$app->basePath . "/uploads/streams/" . $stream->creator_id;
                        if (!file_exists($filename)) {
                            mkdir($filename, 0755);
                        }
                        $imageFile = base64_decode($image);
                        $f = finfo_open();
                        $mime_type = finfo_buffer($f, $imageFile, FILEINFO_MIME_TYPE);
                        $ext = $this->type_to_ext($mime_type);
                        $name = Yii::$app->getSecurity()->generateRandomString() . $ext;
                        $file = Yii::$app->basePath . '/uploads/streams/' . $stream->creator_id . '/' . $name;
                        file_put_contents($file, $imageFile);

                        $streamPhoto->stream_id = $stream->id;
                        $streamPhoto->name = $name;

                        if ($streamPhoto->validate()) {
                            if (!$streamPhoto->save()) {
                                $errorFlag = true;
                                foreach ($streamPhoto->getErrors() as $error) {
                                    $errorMessage .= $error[0];
                                }
                            }
                            $imageCount++;
                        } else {
                            $errorFlag = true;
                            foreach ($streamPhoto->getErrors() as $error) {
                                $errorMessage .= $error[0];
                            }
                        }
                    }
                    if ($imageCount < 2) {
                        $errorFlagImage = true;
                    }
                    $dateEnd = $time + $streamData->life_time;
                    $streamTimer = new StreamTimer();
                    $streamTimer->stream_id = $stream->id;
                    $streamTimer->date_start = $currentTime;
                    $streamTimer->date_end = date('Y-m-d H:i:s', $dateEnd);
                    if ($streamTimer->validate()) {
                        if (!$streamTimer->save()) {
                            $errorFlag = true;
                            foreach ($streamTimer->getErrors() as $error) {
                                $errorMessage .= $error[0];
                            }
                        }
                    } else {
                        $errorFlag = true;
                        foreach ($streamTimer->getErrors() as $error) {
                            $errorMessage .= $error[0];
                        }
                    }
                } else {
                    $errorFlag = true;
                    foreach ($stream->getErrors() as $error) {
                        $errorMessage .= $error[0];
                    }
                }
            } catch (Exception $e) {
                //add error string to stream log file
                Yii::info($e->getMessage(), 'stream');
                $transaction->rollBack();
                $this->sendResponse(501);
                Yii::$app->end();
            }
            if ($errorFlag) {
                //add error string to stream log file
                Yii::info($errorMessage, 'stream');
                $transaction->rollBack();
                $this->sendResponse(501);
                Yii::$app->end();
            }
            if ($errorFlagImage) {
                $transaction->rollBack();
                Yii::info('Image Count < 2', 'stream');
                $this->sendResponse(400);
            }

            $transaction->commit();
            //tags creator
            if($streamData->tags)
            {
                foreach ($streamData->tags as $oneTag)
                {
                    $streamElastic = StreamElastic::find()->where(['tag' => $oneTag])->one();
                    if($streamElastic) //update
                    {
                        //steram to id
                        $id_to_array = json_decode($streamElastic->stream_to_tag);
                        $id_to_array [] = $stream->id;
                        $streamElastic->stream_to_tag = json_encode($id_to_array);
                        
                        $streamElastic->update();
                    }
                    else //create
                    {
                        $streamElastic = new StreamElastic();
                        //steram to id
                        $id_to_array = array();
                        $id_to_array [] = $stream->id;
         
                        $stream_to_tag = json_encode($id_to_array);
                        
                        $streamElastic->attributes = ['tag' => $oneTag, 'stream_to_tag' => $stream_to_tag];
                        $streamElastic->save();
                    }
                }
            }
            
            //send push to friends
            $personTo = Friends::find()->where('lower_id = :id or higher_id = :id',['id' => $person->id])->all();
            $tokens = [];
            if($personTo){
                foreach ($personTo as $onePerson) {
                    if($onePerson->lower_id == $person->id) {
                        $friend = Person::find()
                                ->where('id = :id',['id' => $onePerson->higher_id])
                                ->one();
                        if($friend) {
                            if($friend->personPush->friends_photo) {
                           
                                $tokenData = MobileDevices::find()->where('email = :email',['email' =>$friend->email])->all();

                                if($tokenData) {
                                    $tokens[] = $tokenData->device_token;
                                }
                                   
                            }
                        }
                    } else {
                        $friend = Person::find()
                                ->where('id = :id',['id' => $onePerson->lower_id])
                                ->one();
                        if($friend) {
                            if($friend->personPush->friends_photo) {
                           
                                $tokenData = MobileDevices::find()->where('email = :email',['email' =>$friend->email])->all();

                                if($tokenData) {
                                    $tokens[] = $tokenData->device_token;
                                }
                                   
                            }
                        }
                    }
                }
                
            }
            if($tokens) {
                $message = $person->username." added new photo.";
                $apns = Yii::$app->apns;
                $apns->sendMulti($tokens, $message,
                    [
                        'sound' => 'default',
                        'badge' => 1,
                        'person_id' => $person->id,
                        'type' => 'new_photo'
                    ]
                );
            }
            
            $this->sendResponse(200, true, [], 'Ok');
        } else {
            $this->sendResponse(400);
        }
    }

    public function actionGetStreamList()
    {
        $request = Yii::$app->request->post();
        $person = $this->checkPersonAuthByToken($request['token']);
        $streamData = json_decode($request['data']);
        if (!$streamData->person_id) {
            $streams = Stream::find()->where('creator_id = :id AND id > :last_id', [':id' => $person->id, ':last_id' => $streamData->last_id])
                ->with('photos')
                ->limit(20)
                ->all();
        } else {
            $params = [':id' => $streamData->person_id, ':last_id' => $streamData->last_id];
            $is_friend = $this->isFriend($person->id, $streamData->person_id);
            if ($is_friend == 1) {
                $streams = Stream::find()->where('creator_id = :id AND id > :last_id', $params)
                    ->with('photos')
                    ->limit(20)
                    ->all();
            } elseif ($is_friend == 0) {
                $streams = Stream::find()->where('creator_id = :id AND is_private = 0 AND id > :last_id', $params)
                    ->with('photos')
                    ->limit(20)
                    ->all();
            }
        }

        $data = [];
        if (isset($streams)) {
            foreach ($streams as $stream) {
                $images = [];
                foreach ($stream->photos as $photo) {
                    $images[] = Yii::$app->params['imagePath'] . $stream->creator_id . '/' . $photo->name;
                }
                $data [] = [
                    'stream_id' => $stream->id,
                    'images' => $images,
                ];
            }
            $this->sendResponse(200, true, $data, 'Ok');
        } else {
            $this->sendResponse(200, true, [], 'Ok');
        }
    }
    
    public function actionGetStreamByParam() {
        $request = Yii::$app->request->post();
        $person = $this->checkPersonAuthByToken($request['token']);
        $streamData = json_decode($request['data']);
        if (!$streamData) {
            $this->sendResponse(400);
        }
        if(isset($streamData->streams)) {
            $condition = ['id' => $streamData->streams];
        } elseif (isset($streamData->themes_id)) {
            $condition = ['themes_id' => $streamData->themes_id];
        } else {
            $this->sendResponse(400);
        }
        $data = [];
        $streamAll = Stream::find()->where($condition)->orderBy('nid DESC')->all();
        foreach ($streamAll as $stream) {
            $avatarData = PersonImage::find()->where('person_id = :id', [':id' => $stream->creator_id])->one();
            $avatar = $avatarData ? Yii::$app->params['avatarPath'].$avatarData->img_blob : '';

            $commentData = Comment::find()->where('stream_id = :id',[':id' => $stream->id])->orderBy('date DESC')->all();

            $comments = [];
            $commentsTmp = [];

            if($commentData){
                $i = 0;
                foreach($commentData as $comment){
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
                    if($i==3) break;
                    $avatarDataComment = PersonImage::find()->where('person_id = :id', [':id' => $comment->person_id])->one();
                    $personAvatar = $avatarDataComment ? Yii::$app->params['avatarPath'].$avatarDataComment->img_blob : '';                    
                    $commentsTmp[] = [
                        'comment_id' => $comment->id,
                        'text' => base64_decode($comment->text),
                        'person_id' => $comment->person_id,
                        'person_name' => $personName,
                        'person_image' => $personAvatar,
                        'parent_com_id' => ($comment->parent_com_id && $parentName) ? $comment->parent_com_id : '',
                        'parent_com_name' => $parentName,
                        'parent_com_image' => $parentAvatar
                    ];
                    $i++;
                }
            }

            $comments = array_reverse($commentsTmp);

            $flag = 0;

            foreach($stream->photos as $photo){
                if($this->isLiked($photo->id,$person->id)){
                    $flag = 1; break;
                }
                else{
                    $flag = 0;
                }
            }

            $images = [];

            if ($flag == 1) {
                foreach ($stream->photos as $photo) {
                    $this->likeCount($photo->id) ? $likes = $this->likeCount($photo->id) : $likes = 0;
                    $images[] = [
                        'image_id' => $photo->id,
                        'url' => Yii::$app->params['imagePath'] . $stream->creator_id . '/' . $photo->name,
                        'likes' => $likes,
                        'is_liked' => $this->isLiked($photo->id, $person->id)
                    ];
                }
            } elseif ($stream->is_active == 0) {
                foreach ($stream->photos as $photo) {
                    $this->likeCount($photo->id) ? $likes = $this->likeCount($photo->id) : $likes = 0;
                    $images[] = [
                        'image_id' => $photo->id,
                        'url' => Yii::$app->params['imagePath'] . $stream->creator_id . '/' . $photo->name,
                        'likes' => $likes,
                    ];
                }
            } else {
                foreach ($stream->photos as $photo) {
                    $images[] = [
                        'image_id' => $photo->id,
                        'url' => Yii::$app->params['imagePath'] . $stream->creator_id . '/' . $photo->name,
                    ];
                }
            }

            $data[] = [
                'id' => $stream->id,
                'nid' => $stream->nid,
                'creator_id' => $stream->creator_id,
                'description' => base64_decode($stream->description),
                'username' => $stream->creator->username,
                'avatar' => $avatar?$avatar:'',
                'location' => base64_decode($stream->place_name),
                'images' => $images,
                'comments' => $comments?$comments:[],
                'comments_count' => count($comments),
                'is_active' => $stream->is_active
            ];
        }
        $this->sendResponse(200, true, $data, 'Ok');
    }

    public function actionDeleteStream()
    {
        $request = Yii::$app->request->post();
        $person = $this->checkPersonAuthByToken($request['token']);
        $deleteData = json_decode($request['data']);
        $photos = StreamPhoto::find()->where('stream_id = ' . $deleteData->stream_id)->all();
        if ($deleteData->stream_id) {
            $condition = 'creator_id = :person_id AND id = :stream_id';
            $params = [
                ':person_id' => $person->id,
                ':stream_id' => $deleteData->stream_id
            ];
            if (!Stream::deleteAll($condition, $params)) {

                $this->sendResponse(501);
            }
        } else {
            $this->sendResponse(400);
        }
        foreach ($photos as $onePhoto) {
            $filename = Yii::$app->basePath . "/uploads/streams/" . $person->id . '/' . $onePhoto->name;
            if (file_exists($filename)) {
                unlink($filename);
            }
        }
        $this->sendResponse(200, true, [], 'Ok');
    }

    public function actionGetLifeTime()
    {
        $request = Yii::$app->request->post();
        $this->checkPersonAuthByToken($request['token']);
        $lifeTimeList = LifeTime::findAll(['is_active' => 1]);
        foreach ($lifeTimeList as $lifeTime) {
            $data [] = $lifeTime->life_time;
        }
        $this->sendResponse(200, true, $data, 'Ok');
    }

    public function actionGetFeed()
    {
        $request = Yii::$app->request->post();
        $person = $this->checkPersonAuthByToken($request['token']);
        $requestData = json_decode($request['data']);

        $data = [];

        if($requestData->last==0){
            $connection = Yii::$app->db;
            $command = $connection->createCommand('CALL hw_max_nid');
            $max = $command->queryAll();

            if (is_null($max[0]['maximum'])) {
                $last_index = 10;
            } else {
                $last_index = $max[0]['maximum'] + 1;
            }
        }
        else{
            $last_index = $requestData->last;
        }

        if ($requestData->type == 1) {
            $params = [
                ':pid' => $person->id
            ];
            $friends = Friends::find()->where('(lower_id = :pid OR higher_id = :pid) AND is_follower_lower = 0 AND is_follower_higher = 0', $params)->all();
            $blocked = PersonBlock::find()->select('to_id')->where('from_id = :pid ', $params)->all();

            $blockList = [];
            $personList = [];

            foreach($blocked as $block){
                $blockList[] = $block->to_id;
            }

            foreach($friends as $friend){
                if($friend->lower_id == $person->id){
                    if(!in_array($friend->higher_id,$blockList)){
                        $personList[] = $friend->higher_id;
                    }
                }
                else{
                    if(!in_array($friend->lower_id,$blockList)){
                        $personList[] = $friend->lower_id;
                    }
                }
            }

            if($requestData->region){
                $streams = Stream::find()
                    ->where(['IN', 'creator_id', $personList])
                    ->andWhere(['IN', 'region_id', $requestData->region])
                    ->andWhere('nid < :last', [':last' => $last_index])
                    ->andWhere(['is_active' => 1])
                    ->with('photos')
                    ->with('creator')
                    ->limit(20)
                    ->orderBy('nid DESC')
                    ->all();
            }
            else{
                $streams = Stream::find()
                    ->where(['IN', 'creator_id', $personList])
                    ->andWhere(['IN', 'region_id', $person->region_id])
                    ->andWhere('nid < :last', [':last' => $last_index])
                    ->andWhere(['is_active' => 1])
                    ->with('photos')
                    ->with('creator')
                    ->limit(20)
                    ->orderBy('nid DESC')
                    ->all();
            }
        }
        else{
            if($requestData->region){
                $streams = Stream::find()
                    ->andWhere(['is_active' => 1])
                    ->andWhere(['IN', 'region_id', $requestData->region])
                    ->andWhere('nid < :last', [':last' => $last_index])
                    ->with('photos')
                    ->with('creator')
                    ->limit(20)
                    ->orderBy('nid DESC')
                    ->all();
            }
            else{
                $streams = Stream::find()
                    ->andWhere(['IN', 'region_id', $person->region_id])
                    ->andWhere(['is_active' => 1])
                    ->andWhere('nid < :last', [':last' => $last_index])
                    ->with('photos')
                    ->with('creator')
                    ->limit(20)
                    ->orderBy('nid DESC')
                    ->all();
            }
        }

        if(!$streams){
            $this->sendResponse(200, true, [], 'Ok');
        }

        foreach($streams as $stream){
            $avatarData = PersonImage::find()->where('person_id = :id', [':id' => $stream->creator_id])->one();
            $avatar = $avatarData ? Yii::$app->params['avatarPath'].$avatarData->img_blob : '';

            $commentData = Comment::find()->where('stream_id = :id',[':id' => $stream->id])->orderBy('date DESC')->all();

            $comments = [];
            $commentsTmp = [];

            if($commentData){
                $i = 0;
                foreach($commentData as $comment){
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
                    if($i==3) break;
                    $commentAvatarData = PersonImage::find()->where('person_id = :id', [':id' => $comment->person_id])->one();
                    $personAvatar = $commentAvatarData ? Yii::$app->params['avatarPath'].$commentAvatarData->img_blob : '';
                    
                    $commentsTmp[] = [
                        'comment_id' => $comment->id,
                        'text' => base64_decode($comment->text),
                        'person_id' => $comment->person_id,
                        'person_name' => $personName,
                        'person_image' => $personAvatar,
                        'parent_com_id' => ($comment->parent_com_id && $parentName) ? $comment->parent_com_id : '',
                        'parent_com_name' => $parentName ? $parentName : '',
                        'parent_com_image' => $parentAvatar
                    ];
                    $i++;
                }
            }

            $comments = array_reverse($commentsTmp);

            $flag = 0;

            foreach($stream->photos as $photo){
                if($this->isLiked($photo->id,$person->id)){
                    $flag = 1; break;
                }
                else{
                    $flag = 0;
                }
            }

            $images = [];

            if ($flag == 1) {
                foreach ($stream->photos as $photo) {
                    $this->likeCount($photo->id) ? $likes = $this->likeCount($photo->id) : $likes = 0;
                    $images[] = [
                        'image_id' => $photo->id,
                        'url' => Yii::$app->params['imagePath'] . $stream->creator_id . '/' . $photo->name,
                        'likes' => $likes,
                        'is_liked' => $this->isLiked($photo->id,$person->id)
                    ];
                }
            } elseif ($stream->is_active == 0) {
                foreach ($stream->photos as $photo) {
                    $this->likeCount($photo->id) ? $likes = $this->likeCount($photo->id) : $likes = 0;
                    $images[] = [
                        'image_id' => $photo->id,
                        'url' => Yii::$app->params['imagePath'] . $stream->creator_id . '/' . $photo->name,
                        'likes' => $likes,
                    ];
                }
            } else {
                foreach ($stream->photos as $photo) {
                    $images[] = [
                        'image_id' => $photo->id,
                        'url' => Yii::$app->params['imagePath'] . $stream->creator_id . '/' . $photo->name,
                    ];
                }
            }

            $data[] = [
                'id' => $stream->id,
                'nid' => $stream->nid,
                'creator_id' => $stream->creator_id,
                'description' => base64_decode($stream->description),
                'username' => $stream->creator->username,
                'avatar' => $avatar?$avatar:'',
                'location' => base64_decode($stream->place_name),
                'images' => $images,
                'comments' => $comments?$comments:[],
                'comments_count' => count($comments),
                'is_active' => $stream->is_active
            ];
        }

        $this->sendResponse(200, true, $data, 'Ok');
    }
    
    public function actionGetStream() {
        $request = Yii::$app->request->post();
        $person = $this->checkPersonAuthByToken($request['token']);
        $requestData = json_decode($request['data']);
        if (!$requestData->stream_id) {
            $this->sendResponse(400);
        }
        $stream = Stream::findOne(['id' => $requestData->stream_id]);
        if (!$stream) {
            $this->sendResponse(404);
        }
        $avatarData = PersonImage::find()->where('person_id = :id', [':id' => $stream->creator_id])->one();
        $avatar = $avatarData ? Yii::$app->params['avatarPath'].$avatarData->img_blob : '';
        $commentData = Comment::find()->where('stream_id = :id', [':id' => $stream->id])->orderBy('date DESC')->all();

        $comments = [];
        $commentsTmp = [];

        if ($commentData) {
            $i = 0;
            foreach($commentData as $comment){
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
                if($i==3) break;
                $commentAvatarData = PersonImage::find()->where('person_id = :id', [':id' => $comment->person_id])->one();
                $personAvatar = $commentAvatarData ? Yii::$app->params['avatarPath'].$commentAvatarData->img_blob : '';

                $commentsTmp[] = [
                    'comment_id' => $comment->id,
                    'text' => base64_decode($comment->text),
                    'person_id' => $comment->person_id,
                    'person_name' => $personName,
                    'person_image' => $personAvatar,
                    'parent_com_id' => ($comment->parent_com_id && $parentName) ? $comment->parent_com_id : '',
                    'parent_com_name' => $parentName ? $parentName : '',
                    'parent_com_image' => $parentAvatar
                ];
                $i++;
            }
        }

        $comments = array_reverse($commentsTmp);

        $flag = 0;

        foreach ($stream->photos as $photo) {
            if ($this->isLiked($photo->id, $person->id)) {
                $flag = 1;
                break;
            } else {
                $flag = 0;
            }
        }

        $images = [];

        if ($flag == 1) {
            foreach ($stream->photos as $photo) {
                $this->likeCount($photo->id) ? $likes = $this->likeCount($photo->id) : $likes = 0;
                $images[] = [
                    'image_id' => $photo->id,
                    'url' => Yii::$app->params['imagePath'] . $stream->creator_id . '/' . $photo->name,
                    'likes' => $likes,
                    'is_liked' => $this->isLiked($photo->id,$person->id)
                ];
            }
        } elseif ($stream->is_active == 0) {
            foreach ($stream->photos as $photo) {
                $this->likeCount($photo->id) ? $likes = $this->likeCount($photo->id) : $likes = 0;
                $images[] = [
                    'image_id' => $photo->id,
                    'url' => Yii::$app->params['imagePath'] . $stream->creator_id . '/' . $photo->name,
                    'likes' => $likes,
                ];
            }
        } else {
            foreach ($stream->photos as $photo) {
                $images[] = [
                    'image_id' => $photo->id,
                    'url' => Yii::$app->params['imagePath'] . $stream->creator_id . '/' . $photo->name,
                ];
            }
        }

        $data = [
            'id' => $stream->id,
            'nid' => $stream->nid,
            'creator_id' => $stream->creator_id,
            'description' => base64_decode($stream->description),
            'username' => $stream->creator->username,
            'avatar' => $avatar?$avatar:'',
            'location' => base64_decode($stream->place_name),
            'images' => $images,
            'comments' => $comments ? $comments : [],
            'comments_count' => count($comments),
            'is_active' => $stream->is_active
        ];
        $this->sendResponse(200, true, $data, 'Ok');
    }

}
