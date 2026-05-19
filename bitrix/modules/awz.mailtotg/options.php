<?php
require_once($_SERVER["DOCUMENT_ROOT"]."/bitrix/modules/main/include/prolog_admin_before.php");

use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Config\Option;
use Bitrix\Main\Loader;
use Bitrix\Main\Application;
use Bitrix\Main\UI\Extension;
use Awz\Mailtotg\Access\AccessController;
use Awz\Mailtotg\Helper;

Loc::loadMessages(__FILE__);
global $APPLICATION;
$module_id = "awz.mailtotg";
if(!Loader::includeModule($module_id)) return;
Extension::load('ui.sidepanel-content');
$request = Application::getInstance()->getContext()->getRequest();
$APPLICATION->SetTitle(Loc::getMessage('AWZ_MAILTOTG_OPT_TITLE'));

if($request->get('IFRAME_TYPE')==='SIDE_SLIDER'){
    require($_SERVER["DOCUMENT_ROOT"]."/bitrix/modules/main/include/prolog_admin_after.php");
    require_once('lib/access/include/moduleright.php');
    CMain::finalActions();
    die();
}

if(!AccessController::isViewSettings())
    $APPLICATION->AuthForm(Loc::getMessage("ACCESS_DENIED"));
require($_SERVER["DOCUMENT_ROOT"]."/bitrix/modules/main/include/prolog_admin_after.php");

if ($request->getRequestMethod()==='POST' && AccessController::isEditSettings() && $request->get('Update'))
{
    $finOptions = [];
    $event = $request->get('EVENT');
    if(!is_array($event)) $event = [];
    foreach($event as $id=>$ev){
        if(!is_array($ev)) continue;
        $mask = 0;
        if($ev['active']) $mask = $mask | Helper::MASK_ACTIVE;
        if($ev['decline']) $mask = $mask | Helper::MASK_DECLINE;
        if($mask>0) $finOptions[$id] = $mask;
    }
    Option::set($module_id, "OPTS", serialize($finOptions), "");
    Option::set($module_id, "TGKEY", $request->get('TGKEY'), "");
    Option::set($module_id, "TGID", $request->get('TGID'), "");
}

$aTabs = array();

$aTabs[] = array(
    "DIV" => "edit1",
    "TAB" => Loc::getMessage('AWZ_MAILTOTG_OPT_SECT1'),
    "ICON" => "vote_settings",
    "TITLE" => Loc::getMessage('AWZ_MAILTOTG_OPT_SECT1')
);

$saveUrl = $APPLICATION->GetCurPage(false).'?mid='.htmlspecialcharsbx($module_id).'&lang='.LANGUAGE_ID.'&mid_menu=1';
$tabControl = new CAdminTabControl("tabControl", $aTabs);
$tabControl->Begin();
?>
    <style>.adm-workarea option:checked {background-color: rgb(206, 206, 206);}</style>
    <form method="POST" action="<?=$saveUrl?>" id="FORMACTION">
        <?
        $tabControl->BeginNextTab();
        Extension::load("ui.alerts");
        ?>
        <tr>
            <td style="width:200px;"><?=Loc::getMessage('AWZ_MAILTOTG_OPT_TGKEY')?></td>
            <td>
                <?$val = Option::get($module_id, "TGKEY", "","");?>
                <input type="text" name="TGKEY" value="<?=htmlspecialcharsEx($val)?>"></td>
            </td>
        </tr>
        <tr>
            <td style="width:200px;"><?=Loc::getMessage('AWZ_MAILTOTG_OPT_TGID')?></td>
            <td>
                <?$val = Option::get($module_id, "TGID", "",""); ?>
                <input type="text" name="TGID" value="<?=htmlspecialcharsEx($val)?>"></td>
            </td>
        </tr>
        <tr>
            <td colspan="2">
                <div class="ui-alert ui-alert-primary">
                    <span class="ui-alert-message">
                        <?=Loc::getMessage('AWZ_MAILTOTG_OPT_SHOW_DESC')?>
                    </span>
                </div>
            </td>
        </tr>
        <tr>
            <td colspan="2"><style>.awz-opts-table {border-spacing:0;}.awz-opts-table tr:nth-child(even) {background:#fff;}.awz-opts-table tr td {padding:3px;}</style><table class="awz-opts-table" style="width:100%;">
                    <tr>
                        <th style="text-align: left;min-width:80px;">ID</th>
                        <th style="text-align: left;min-width:80px;"><?=Loc::getMessage('AWZ_MAILTOTG_OPT_SHOW_DESC_1')?></th>
                        <th style="text-align: left;"><?=Loc::getMessage('AWZ_MAILTOTG_OPT_SHOW_DESC_2')?></th>
                        <th style="text-align: left;"><?=Loc::getMessage('AWZ_MAILTOTG_OPT_SHOW_DESC_3')?></th>
                        <th style="text-align: left;"><?=Loc::getMessage('AWZ_MAILTOTG_OPT_SHOW_DESC_4')?></th>
                        <th style="text-align: left;"><?=Loc::getMessage('AWZ_MAILTOTG_OPT_SHOW_DESC_5')?></th>
                    </tr>
        <?
        $allType = \Bitrix\Main\Mail\Internal\EventMessageTable::getList(array(
            'select' => array('*'),
            'filter' => array('ACTIVE'=>'Y'),
            'limit'=>1000,
            'order'=>['LID'=>'ASC','EVENT_NAME'=>'ASC','ID'=>'DESC']
        ));
        $arAllType = array();
        while($dt = $allType->fetch()){
            ?>
                    <tr>
                        <td><?=$dt['ID']?></td>
                        <td><?=$dt['LID']?></td>
                        <td><?=$dt['EVENT_NAME']?></td>
                        <td><?=$dt['SUBJECT']?></td>
                        <td>
                            <input type="checkbox" value="Y" name="EVENT[<?=$dt['ID']?>][active]" <?if (Helper::isActive((int)$dt['ID'])) echo "checked";?>>
                        </td>
                        <td>
                            <input type="checkbox" value="Y" name="EVENT[<?=$dt['ID']?>][decline]" <?if (Helper::isDecline((int)$dt['ID'])) echo "checked";?>>
                        </td>
                    </tr>

                </td>

            <?
        }
        ?></table>
            </td>
        </tr>
        <?
        $tabControl->Buttons();
        ?>
        <input <?if (!AccessController::isEditSettings()) echo "disabled" ?> type="submit" class="adm-btn-green" name="Update" value="<?=Loc::getMessage('AWZ_MAILTOTG_OPT_L_BTN_SAVE')?>" />
        <input type="hidden" name="Update" value="Y" />
        <?if(AccessController::isViewRight()){?>
            <button class="adm-header-btn adm-security-btn" onclick="BX.SidePanel.Instance.open('<?=$saveUrl?>');return false;">
                <?=Loc::getMessage('AWZ_MAILTOTG_OPT_SECT2')?>
            </button>
        <?}?>
        <?$tabControl->End();?>
    </form>
<?
require($_SERVER["DOCUMENT_ROOT"]."/bitrix/modules/main/include/epilog_admin.php");