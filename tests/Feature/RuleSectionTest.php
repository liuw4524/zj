<?php

namespace Tests\Feature;

use App\Filament\Resources\RuleSectionResource\Pages\CreateRuleSection;
use App\Filament\Resources\RuleSectionResource\Pages\EditRuleSection;
use App\Filament\Resources\RuleSectionResource\Pages\ListRuleSections;
use App\Models\RuleSection;
use App\Support\DebugUsers;
use Database\Seeders\DatabaseSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class RuleSectionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    public function test_rules_follow_admin_edits_in_sort_order(): void
    {
        $this->getJson('/api/rules')
            ->assertOk()
            ->assertJsonPath('data.title', '自愿酬谢说明')
            ->assertJsonPath('data.sections.0.title', '寻宠感谢金')
            ->assertJsonPath('data.sections.4.title', '线下结清');

        Livewire::actingAs(DebugUsers::operator())
            ->test(CreateRuleSection::class)
            ->fillForm([
                'title' => '补充说明',
                'body' => '这是新增的一条规则。',
                'sort' => 0,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->getJson('/api/rules')
            ->assertJsonPath('data.sections.0.title', '补充说明')
            ->assertJsonPath('data.sections.0.body', '这是新增的一条规则。');

        $section = RuleSection::query()->where('title', '寻宠感谢金')->firstOrFail();

        Livewire::actingAs(DebugUsers::operator())
            ->test(EditRuleSection::class, ['record' => $section->id])
            ->fillForm([
                'title' => '感谢金',
                'body' => '改过的内容。',
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->getJson('/api/rules')
            ->assertJsonPath('data.sections.1.title', '感谢金')
            ->assertJsonPath('data.sections.1.body', '改过的内容。');

        Livewire::actingAs(DebugUsers::operator())
            ->test(ListRuleSections::class)
            ->callTableAction('delete', $section);

        $this->assertNull(RuleSection::query()->find($section->id));
        $this->getJson('/api/rules')->assertJsonMissing(['title' => '感谢金']);
    }
}
