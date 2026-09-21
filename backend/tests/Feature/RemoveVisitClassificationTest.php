<?php

namespace Tests\Feature;

use App\Support\TestDatabaseSafety;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class RemoveVisitClassificationTest extends TestCase
{
    public function test_upgrade_removes_historical_classification_without_removing_visits(): void
    {
        TestDatabaseSafety::assertAvailable($this->app);
        $original = Schema::getFacadeRoot();
        // Isolated prefixed tables on the guarded MariaDB connection: never alter
        // the clinical tables used by other tests or local synthetic fixtures.
        config(['database.connections.classification_upgrade' => array_replace(
            config('database.connections.mysql'), ['prefix' => 'classification_upgrade_']
        )]);
        $connection = DB::connection('classification_upgrade');
        $schema = $connection->getSchemaBuilder();
        $schema->create('visit_types', function (Blueprint $t) {
            $t->id();
            $t->string('name');
        });
        $schema->create('visits', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('visit_type_id');
            $t->date('visit_date');
            $t->string('clinical_note');
            $t->foreign('visit_type_id', 'fk_visits_edf5049a91')->references('id')->on('visit_types');
        });
        try {
            $connection->table('visit_types')->insert(['id' => 1, 'name' => 'تصنيف تاريخي']);
            $connection->table('visits')->insert(['id' => 1, 'visit_type_id' => 1, 'visit_date' => '2001-03-02', 'clinical_note' => 'بيانات طبية محفوظة']);
            Schema::swap($schema);
            $migration = require database_path('migrations/2026_09_22_000001_remove_visit_classification.php');
            $migration->up();
            $this->assertFalse($schema->hasTable('visit_types'));
            $this->assertFalse($schema->hasColumn('visits', 'visit_type_id'));
            $this->assertSame('بيانات طبية محفوظة', $connection->table('visits')->value('clinical_note'));
            $this->assertSame('2001-03-02', $connection->table('visits')->value('visit_date'));
            $connection->table('visits')->insert(['visit_date' => '2001-03-03', 'clinical_note' => 'زيارة جديدة دون تصنيف']);
            $this->assertSame(2, $connection->table('visits')->count());
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('irreversible');
            $migration->down();
        } finally {
            Schema::swap($original);
            $schema->dropIfExists('visits');
            $schema->dropIfExists('visit_types');
            DB::purge('classification_upgrade');
        }
    }
}
