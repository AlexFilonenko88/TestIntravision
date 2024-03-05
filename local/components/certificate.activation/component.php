<?php
if(!defined("B_PROLOG_INCLUDED")||B_PROLOG_INCLUDED!==true)die();
include($_SERVER["DOCUMENT_ROOT"] . "/local/php_interface/tcpdf/tcpdf.php");

use Bitrix\Main\Loader;
use Bitrix\Main\Type\Date;

global $USER;

if(!$USER->IsAuthorized()){
	return;
}
if (!Loader::includeModule('iblock'))
{
	return;
}

/**
 * Bitrix vars
 *
 * @var array $arParams
 * @var array $arResult
 * @var CBitrixComponent $this
 * @global CMain $APPLICATION
 * @global CUser $USER
 */


function addValueToPropertyEx ($arCertificates, $arParams, $type) {
	global $USER;
	$value = $arCertificates["ACTIVATED_" . $type];
	if($type === "USERS"){
		$value[] = $USER->GetID();
	}elseif($type === "DATES"){
		$value[] = new Date();
	}
	CIBlockElement::SetPropertyValuesEx($arCertificates["ID"], $arParams["IBLOCK_ID"], [$arParams["ACTIVATED_" . $type] => $value]);
}

function getCertificateByName ($arCertificates, $name, $arParams) {
	foreach($arCertificates as $certificate){
		if($certificate["NAME"] === $name){
			return [
				"ID" => $certificate["ID"],
				"NAME" => $certificate["NAME"],
				"ACTIVATED_USERS" => $certificate["PROPERTY_" . $arParams["ACTIVATED_USERS"] . "_VALUE"],
				"ACTIVATED_DATES" => $certificate["PROPERTY_" . $arParams["ACTIVATED_DATES"] . "_VALUE"],
			];
		}
	}
}

$arResult["PARAMS_HASH"] = md5(serialize($arParams).$this->GetTemplateName());

$arParams["USE_CAPTCHA"] = (($arParams["USE_CAPTCHA"] != "N" && !$USER->IsAuthorized()) ? "Y" : "N");
$arParams["EVENT_NAME"] = trim($arParams["EVENT_NAME"]);
if($arParams["EVENT_NAME"] == '')
	$arParams["EVENT_NAME"] = "FEEDBACK_FORM";
$arParams["EMAIL_TO"] = trim($arParams["EMAIL_TO"]);
if($arParams["EMAIL_TO"] == '')
	$arParams["EMAIL_TO"] = COption::GetOptionString("main", "email_from");
$arParams["OK_TEXT"] = trim($arParams["OK_TEXT"]);
if($arParams["OK_TEXT"] == '')
	$arParams["OK_TEXT"] = GetMessage("MF_OK_MESSAGE");

$res = CIBlockElement::GetList(
	["ID" => "DESC"],
	["IBLOCK_ID" => $arParams["IBLOCK_ID"], "ACTIVE" => "Y"], 
	false, 
	false, 
	["IBLOCK_ID", "ID", "NAME", "PROPERTY_" . $arParams["ACTIVATED_USERS"], "PROPERTY_" . $arParams["ACTIVATED_DATES"]]
);

while($ar = $res->GetNext()){
	if(in_array($USER->GetID(), $ar["PROPERTY_" . $arParams["ACTIVATED_USERS"] . "_VALUE"])){
		$arResult["CERTIFICATES"]["ACTIVATED"][] = $ar;
	}else{
		$arResult["CERTIFICATES"]["NOT_ACTIVATED"][] = $ar;
	}
}

if($_SERVER["REQUEST_METHOD"] == "POST" && $_POST["submit"] <> '' && (!isset($_POST["PARAMS_HASH"]) || $arResult["PARAMS_HASH"] === $_POST["PARAMS_HASH"]))
{
	$arResult["ERROR_MESSAGE"] = array();
	if(check_bitrix_sessid())
	{
		if(mb_strlen($_POST["certificate"]) <= 1){
			$arResult["ERROR_MESSAGE"][] = "Вы не ввели номер сертификата";
		}
		if($arParams["USE_CAPTCHA"] == "Y")
		{
			$captcha_code = $_POST["captcha_sid"];
			$captcha_word = $_POST["captcha_word"];
			$cpt = new CCaptcha();
			$captchaPass = COption::GetOptionString("main", "captcha_password", "");
			if ($captcha_word <> '' && $captcha_code <> '')
			{
				if (!$cpt->CheckCodeCrypt($captcha_word, $captcha_code, $captchaPass))
					$arResult["ERROR_MESSAGE"][] = GetMessage("MF_CAPTCHA_WRONG");
			}
			else
				$arResult["ERROR_MESSAGE"][] = GetMessage("MF_CAPTHCA_EMPTY");

		}
		if(empty($arResult["ERROR_MESSAGE"])){
			$arFields = Array(
				"CERTIFICATE" => htmlspecialcharsbx($_POST["certificate"]),
				"EMAIL_TO" => $USER->GetEmail(),
			);

			$arCertificatesActivated = getCertificateByName($arResult["CERTIFICATES"]["ACTIVATED"], $arFields["CERTIFICATE"], $arParams);
			$arCertificatesNotActivated = getCertificateByName($arResult["CERTIFICATES"]["NOT_ACTIVATED"], $arFields["CERTIFICATE"], $arParams);

			if($arCertificatesNotActivated){
				addValueToPropertyEx($arCertificatesNotActivated, $arParams, "USERS");
				addValueToPropertyEx($arCertificatesNotActivated, $arParams, "DATES");
				$arResult["SUCCESS_MESSAGE"] = "Сертификат " .  $arFields["CERTIFICATE"] . " активирован";
			}elseif($arCertificatesActivated){
				$arResult["ERROR_MESSAGE"]["IS_ACTIVATED"] = "Сертификат с таким именем уже активирован";
			}else{
				$arResult["ERROR_MESSAGE"]["NOT_FOUND"] = "Сертификат с таким именем не существует";
			}
			
		}
		if(empty($arResult["ERROR_MESSAGE"]))
		{
			// ob_end_clean();
			// $pdf = new TCPDF('P', 'mm', 'A4', true, 'UTF-8');
			// $pdf->SetTitle('Сертификат');
			// $pdf->SetFont('dejavusans', '', 10);
			// $pdf->AddPage();
			// $html = "<h1> Сертификат </h1><br><br><hr><br><br>" . $arFields["CERTIFICATE"] . " (" .  new Date() . ")";
			// $pdf->writeHTML($html, true, false, true, false, '');
			// $pdf->Output($_SERVER['DOCUMENT_ROOT'] .'/upload/pdf.pdf', 'F');

			$file = CFile::MakeFileArray(
				$_SERVER['DOCUMENT_ROOT'] . '/upload/pdf.pdf',
				false,
				false,
				''
			);
			
			$fileId = CFile::SaveFile(
				$file,
				'/tmp',
				false,
				false
			);
			
			$arFiles[] = $fileId;

			if(!empty($arParams["EVENT_MESSAGE_ID"]))
			{
				foreach($arParams["EVENT_MESSAGE_ID"] as $v)
					if(intval($v) > 0)
						CEvent::Send($arParams["EVENT_NAME"], SITE_ID, $arFields, "N", intval($v), $arFiles);
			}
			else
				CEvent::Send($arParams["EVENT_NAME"], SITE_ID, $arFields, "N", "", $arFiles);
			$_SESSION["MF_EMAIL"] = $USER->GetEmail();
			$event = new \Bitrix\Main\Event('main', 'onFeedbackFormSubmit', $arFields);
			$event->send();
			// CFile::Delete($fileId);
			LocalRedirect($APPLICATION->GetCurPageParam("success=".$arResult["PARAMS_HASH"], Array("success")));
		}

		$arResult["CERTIFICATE"] = htmlspecialcharsbx($_POST["certificate"]);
	}
	else
		$arResult["ERROR_MESSAGE"][] = GetMessage("MF_SESS_EXP");
}
elseif($_REQUEST["success"] == $arResult["PARAMS_HASH"])
{
	$arResult["OK_MESSAGE"] = $arParams["OK_TEXT"];
}

if(empty($arResult["ERROR_MESSAGE"]))
{
	if($USER->IsAuthorized())
	{
		$arResult["AUTHOR_NAME"] = $USER->GetFormattedName(false);
		$arResult["AUTHOR_EMAIL"] = htmlspecialcharsbx($USER->GetEmail());
	}
	else
	{
		if($_SESSION["MF_NAME"] <> '')
			$arResult["AUTHOR_NAME"] = htmlspecialcharsbx($_SESSION["MF_NAME"]);
		if($_SESSION["MF_EMAIL"] <> '')
			$arResult["AUTHOR_EMAIL"] = htmlspecialcharsbx($_SESSION["MF_EMAIL"]);
	}
}

if($arParams["USE_CAPTCHA"] == "Y")
	$arResult["capCode"] =  htmlspecialcharsbx($APPLICATION->CaptchaGetCode());

if($fileId){
	CFile::Delete($fileId);
}

$this->IncludeComponentTemplate();
