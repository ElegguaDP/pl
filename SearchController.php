<?php

namespace app\controllers\api;

use Yii;
use app\models\Person;
use app\models\Stream;
use app\models\PersonElastic;
use app\models\StreamElastic;

class SearchController extends ApiController {

    //put your code here
    public function actionSearchPerson() {
        $request = Yii::$app->request->post();
        $this->checkPersonAuthByToken($request['token']);
        $requestData = json_decode($request['data']);

        if (!$requestData->name) {
            $this->sendResponse(400);
        }

        $query = PersonElastic::find()->query([
            "fuzzy_like_this" => [
                "fields" => ["name", "username"],
                "like_text" => $requestData->name,
                "max_query_terms" => 10,
                "fuzziness" => 0.2
            ]
        ]);

        $result = $query->search();

        if (!$result['hits']['hits']) {
            $this->sendResponse(200, true, [], 'No results');
        }

        $personsList = [];
        foreach ($result['hits']['hits'] as $hit) {
            $personsList[] = $hit->id;
        }

        $persons = Person::find()->where(['IN', 'id', $personsList])->with('personImage')->all();

        foreach ($persons as $person) {
            $data[] = [
                'id' => $person->id,
                'username' => $person->username,
                'image' => $person->personImage->img_blob ? Yii::$app->params['avatarPath'].$person->personImage->img_blob : '',
            ];
        }

        $this->sendResponse(200, true, $data, 'Ok');
    }

    public function actionSearchTagList() {
        $request = Yii::$app->request->post();
        $person = $this->checkPersonAuthByToken($request['token']);
        $tagData = json_decode($request['data']);
        if ($tagData->tag) {
            $data = [];
            $query = StreamElastic::find()->query([
                "fuzzy_like_this_field" => [
                    "tag" =>
                    [
                        "like_text" => $tagData->tag,
                        "max_query_terms" => 10,
                        "fuzziness" => 0.2
                    ]
                ]
            ]);
            $tagList = $query->all();
            if (!empty($tagList)) {
                foreach ($tagList as $tag) {
                    $data[] = $tag->attributes;
                }
            }
            $this->sendResponse(200, true, $data, 'Ok');
        } else {
            $this->sendResponse(400);
        }
    }

}
