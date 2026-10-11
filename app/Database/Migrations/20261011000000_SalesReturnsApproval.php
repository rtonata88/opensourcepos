<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class Migration_SalesReturnsApproval extends Migration
{
    /**
     * Perform a migration step.
     */
    public function up(): void
    {
        helper('migration');
        executeScriptWithTransaction(APPPATH . 'Database/Migrations/sqlscripts/3.4.3_sales_returns_approval.sql');
    }

    /**
     * Revert a migration step.
     */
    public function down(): void
    {
        $sales       = $this->db->prefixTable('sales');
        $grants      = $this->db->prefixTable('grants');
        $permissions = $this->db->prefixTable('permissions');

        $this->db->query("ALTER TABLE `{$sales}` DROP FOREIGN KEY `ospos_sales_ibfk_return_approved_by`");
        $this->db->query("ALTER TABLE `{$sales}` DROP COLUMN `return_approved_by`");
        $this->db->query("DELETE FROM `{$grants}` WHERE permission_id = 'sales_returns'");
        $this->db->query("DELETE FROM `{$permissions}` WHERE permission_id = 'sales_returns'");
    }
}
