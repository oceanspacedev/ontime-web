<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Worker foreign keys that used to cascade, so deleting a division, job level or
     * job title silently removed every worker in it together with their history.
     *
     * @var array<string, array<string, string>>
     */
    private array $foreignKeys = [
        'employees' => [
            'division_id' => 'divisions',
            'job_level_id' => 'job_levels',
            'job_title_id' => 'job_titles',
        ],
        'interns' => [
            'division_id' => 'divisions',
        ],
        'freelancers' => [
            'division_id' => 'divisions',
        ],
    ];

    public function up(): void
    {
        $this->rebuildForeignKeys(restrict: true);
    }

    public function down(): void
    {
        $this->rebuildForeignKeys(restrict: false);
    }

    private function rebuildForeignKeys(bool $restrict): void
    {
        foreach ($this->foreignKeys as $tableName => $columns) {
            Schema::table($tableName, function (Blueprint $table) use ($columns, $restrict) {
                foreach ($columns as $column => $referencedTable) {
                    $table->dropForeign([$column]);

                    $foreignKey = $table->foreign($column)->references('id')->on($referencedTable);

                    $restrict ? $foreignKey->restrictOnDelete() : $foreignKey->cascadeOnDelete();
                }
            });
        }
    }
};
