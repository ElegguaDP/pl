<?php

namespace app\controllers\api;

use app\models\Person;
use app\models\Stream;
use app\models\Comment;
use app\models\Report;
use Yii;

class ReportController extends ApiController {

    public function actionSendReport() {
        $request = Yii::$app->request->post();
        $person = $this->checkPersonAuthByToken($request['token']);
        $reportData = json_decode($request['data']);
        if (!$reportData->report_type && !$reportData->data_id) {
            $this->sendResponse(400);
        }
        $time = time();
        $currentTime = date('Y-m-d H:i:s', $time);
        $reportInfo = [
            'person_id' => $person->id,
            'data_id' => $reportData->data_id,
            'data_type' => $reportData->report_type
        ];

        if (!Report::findOne($reportInfo)) {
            $report = new Report();
            $report->person_id = $person->id;
            $report->data_id = $reportData->data_id;
            $report->data_type = $reportData->report_type;
            $report->date = $currentTime;
            if ($report->validate()) {
                if (!$report->save()) {
                    $this->sendResponse(501);
                }
            } else {
                $this->sendResponse(400);
            }
            $this->sendResponse(200, true, [], 'Ok');
        } else {
            $this->sendResponse(200, false, [], 'Report already sent');
        }
    }

}
