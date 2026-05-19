<?php
namespace Awz\Mailtotg\Access\Tables;

use Bitrix\Main\Access\Role\AccessRoleTable;

class RoleTable extends AccessRoleTable
{
    public static function getTableName()
    {
        return 'awz_mailtotg_role';
    }
}