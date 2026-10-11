<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;
use RuntimeException;

class Migration_ItemDeals extends Migration
{
    /**
     * Perform a migration step.
     */
    public function up(): void
    {
        helper('migration');

        if (! executeScriptWithTransaction(APPPATH . 'Database/Migrations/sqlscripts/3.4.4_item_deals.sql')) {
            throw new RuntimeException('Migration ItemDeals failed: could not add deal columns to items.');
        }
    }

    /**
     * Revert a migration step.
     */
    public function down(): void
    {
        $items = $this->db->prefixTable('items');

        $this->db->query("ALTER TABLE `{$items}` DROP COLUMN `deal_quantity`, DROP COLUMN `deal_price`");
    }
}
