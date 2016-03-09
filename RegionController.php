<?php

/*
 * To change this license header, choose License Headers in Project Properties.
 * To change this template file, choose Tools | Templates
 * and open the template in the editor.
 */

namespace app\controllers\api;

use Yii;
use app\models\Region;

class RegionController extends ApiController {

    public function actionIndex() {

        $regionData = Region::find()->all();

        if (!$regionData) {
            $this->sendResponse(200, false, null, 'no data');
        }
        $regionList = [];
        foreach ($regionData as $region) {
            $regionList [] = [
                'region_id' => $region->id,
                'region_name' => $region->name
            ];
        }
        $this->sendResponse(200, true, $regionList, 'ok');
    }

}
