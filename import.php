<?php

$json = file_get_contents('php://input');

if (!empty($json)) {
	$arInput = json_decode($json, true);

	$arrLog = ["входящий json" => $arInput];
    logToFile($arrLog, 'import-notifications');

    if (is_array($arInput)) {
    	 require_once($_SERVER['DOCUMENT_ROOT'] . "/bitrix/modules/main/include/prolog_before.php");

    	$cntAll = 0;

        $arrLog = ["интеграция началась" . date('d.m.Y H:i:s')];
        logToFile($arrLog);

    	$DivisionUpdaterHelper = new \WebIT\Helpers\DivisionUpdaterHelper();

        foreach ($arInput as $login => $code) {
        	$fields = [];

        	$fields['UF_LOGIN'] = $login;
        	$fields['UF_CODE'] = $code;

        	$DivisionUpdaterHelper->addCodesBy1C($fields);

        	unset($fields);
        	$cntAll++;

            $arrLog = [
                "Выгружены коды для пользователя ".$login
            ];
            logToFile($arrLog);
        }

        $arrLog = ["интеграция закончилась" . date('d.m.Y H:i:s')];
        logToFile($arrLog);

        $jsonResult = [
            'ALL' => $cntAll,
            'RESULT' => 'success'
        ];

        echo json_encode($jsonResult);

        die();
    }
}

function logToFile($message, $logName = 'log') {
    $baseDir = $_SERVER['DOCUMENT_ROOT'] . '/tools/division_update_logs/';
    $dateDir = date('Y-m-d');
    $logDir = $baseDir . $dateDir . '/';

    if (!is_dir($logDir)) {
        mkdir($logDir, 0777, true);
    }

    if (is_dir($baseDir)) {
        $dirs = scandir($baseDir);
        foreach ($dirs as $dir) {
            if ($dir === '.' || $dir === '..') continue;
            $fullPath = $baseDir . $dir;
            if (is_dir($fullPath)) {
                $dirDate = DateTime::createFromFormat('Y-m-d', $dir);
                if ($dirDate && $dirDate < (new DateTime())->modify('-30 days')) {
                    $files = glob($fullPath . '/*');
                    foreach ($files as $file) {
                        if (is_file($file)) {
                            unlink($file);
                        }
                    }
                    rmdir($fullPath);
                }
            }
        }
    }

    $filePath = $logDir . $logName . date('H:i:s') . '.txt';
    $logEntry = '[' . date('Y-m-d H:i:s') . '] ' . (is_scalar($message) ? $message : print_r($message, true)) . "\n";
    file_put_contents($filePath, $logEntry, FILE_APPEND);
}

$jsonResult = [
    'RESULT' => 'error'
];

echo json_encode($jsonResult);

die();