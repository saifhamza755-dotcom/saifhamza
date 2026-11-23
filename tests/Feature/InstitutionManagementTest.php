<?php

namespace Tests\Feature;

use App\Models\University;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InstitutionManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    public function test_central_user_can_register_university(): void
    {
        $central = User::where('username', 'central')->firstOrFail();

        $this->actingAs($central)->post(route('institutions.store'), [
            'entity_type' => 'university',
            'name_ar' => 'جامعة تعز',
            'name_en' => 'Taiz University',
            'code' => 'TU',
            'type' => 'public',
            'city' => 'تعز',
        ])->assertRedirect();

        $this->assertDatabaseHas('universities', ['code' => 'TU', 'name_ar' => 'جامعة تعز']);
    }

    public function test_university_manager_sees_only_assigned_university(): void
    {
        University::create(['name_ar' => 'جامعة عدن', 'code' => 'AU', 'type' => 'public', 'active' => true]);
        $manager = User::where('username', 'university')->firstOrFail();

        $this->actingAs($manager)
            ->get(route('institutions.index'))
            ->assertOk()
            ->assertSee('جامعة صنعاء')
            ->assertDontSee('جامعة عدن');
    }

    public function test_university_manager_cannot_add_college_to_another_university(): void
    {
        $other = University::create(['name_ar' => 'جامعة عدن', 'code' => 'AU', 'type' => 'public', 'active' => true]);
        $manager = User::where('username', 'university')->firstOrFail();

        $this->actingAs($manager)->post(route('institutions.store'), [
            'entity_type' => 'college',
            'university_id' => $other->id,
            'name_ar' => 'كلية غير مسموحة',
            'code' => 'BLOCKED',
        ])->assertForbidden();

        $this->assertDatabaseMissing('colleges', ['code' => 'BLOCKED']);
    }

    public function test_college_user_cannot_open_institution_management(): void
    {
        $this->actingAs(User::where('username', 'college')->firstOrFail())
            ->get(route('institutions.index'))
            ->assertForbidden();
    }
}
