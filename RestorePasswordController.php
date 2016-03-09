<?php

namespace app\controllers\api;

use Yii;
use app\models\Person;
use app\models\PersonToken;

/**
 * Generate password for restore
 *
 */
class RestorePasswordController extends ApiController {

    /**
     * generate new password and save to database
     */
    public function actionGeneratePassword() {
        $request = Yii::$app->request->get();
        $person = Person::findOne(['login' => $request['0']['login']]);
        if (!$person) {
            echo ('Email wrong or not exist!');
            Yii::$app->end();
        }
        //check user hash
        if ($person->person_hash != $request['0']['personHash']) {
            echo ('Restore password URL wrong or already used');
            Yii::$app->end();
        }
        //generate new user- and master-password
        mt_srand((double) microtime() * 10000);
        $charid = strtoupper(md5(uniqid(rand(), true)));
        $hyphen = chr(45);
        $personPassword = substr($charid, 0, 5) . $hyphen . substr($charid, 8, 5);
        $person->setPassword($personPassword);
        $person->person_hash = NULL;
        $person->hash_exp_date = NULL;
        if (!$person->save()) {
            echo ('Sorry, operation crashed. Please try again.');
            Yii::$app->end();
        }
        //generate and save new token
        $personToken = $person->personToken;
        $personToken->token = Person::generateAccessToken();
        $personToken->exp_date = date(DATE_W3C, time());
        if (!$personToken->save()) {
            echo ('Sorry, operation crashed. Please try again.');
            Yii::$app->end();
        }

        echo ('User-password:  ' . $personPassword);
    }

}
