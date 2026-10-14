<?php

namespace Tests\Feature\Contracts;

use Tests\Support\AccessFixtures;
use Tests\Support\MigratedDatabase;
use Tests\Support\ValidatesContracts;
use Tests\TestCase;

/**
 * Les routes implémentées (x-implementation-status: IMPLEMENTED dans contracts/openapi.yaml) renvoient
 * exactement les ressources et l'enveloppe d'erreur publiées dans contracts/ (F06), sur MySQL réel.
 */
final class ImplementedRoutesContractTest extends TestCase
{
    use AccessFixtures;
    use MigratedDatabase;
    use ValidatesContracts;

    public function test_session_comptes_et_cabinet_respectent_leurs_schemas(): void
    {
        $clinicId = $this->makeClinic();
        $manager = $this->makeStaff($clinicId, 'MANAGER', 'gerante.test');
        $cashier = $this->makeStaff($clinicId, 'CASHIER', 'caisse.test');

        $this->assertDataMatchesContract((object) ['login' => 'gerante.test', 'password' => self::TEST_PASSWORD], 'schemas/requests/http.json#/$defs/LoginRequest');
        $this->assertMatchesContract($this->login('gerante.test')->assertOk(), 'schemas/resources/access.json#/$defs/MeResponse');
        $this->assertMatchesContract($this->nextRequest()->getJson('/api/v1/auth/me')->assertOk(), 'schemas/resources/access.json#/$defs/MeResponse');
        $this->assertMatchesContract(
            $this->nextRequest()->postJson('/api/v1/auth/reauthenticate', ['password' => self::TEST_PASSWORD])->assertOk(),
            'schemas/resources/access.json#/$defs/ReauthenticationResponse',
        );

        $request = $this->actingAsReauthenticated($manager);
        $this->assertMatchesContract($request->getJson('/api/v1/staff')->assertOk(), 'schemas/resources/access.json#/$defs/StaffMemberPage');
        $this->assertMatchesContract($request->getJson('/api/v1/staff/'.$cashier->id)->assertOk(), 'schemas/resources/access.json#/$defs/StaffMemberResponse');
        $this->assertMatchesContract($request->getJson('/api/v1/clinic/settings')->assertOk(), 'schemas/resources/access.json#/$defs/ClinicSettingsResponse');

        $create = ['login' => 'accueil.test', 'display_name' => 'Accueil fictif', 'email' => null, 'password' => 'InitialFictif2026', 'roles' => ['RECEPTION']];
        $this->assertDataMatchesContract(json_decode((string) json_encode($create)), 'schemas/requests/http.json#/$defs/CreateStaffRequest');
        $created = $request->postJson('/api/v1/staff', $create)->assertCreated();
        $this->assertMatchesContract($created, 'schemas/resources/access.json#/$defs/StaffMemberResponse');

        $override = ['expected_version' => 1, 'permission_code' => 'exam_fee.collect', 'effect' => 'ALLOW', 'reason' => 'Encaissement des frais d\'examen'];
        $this->assertDataMatchesContract(json_decode((string) json_encode($override)), 'schemas/requests/http.json#/$defs/PermissionOverrideRequest');
        $this->assertMatchesContract(
            $request->postJson('/api/v1/staff/'.$created->json('data.id').'/permissions', $override)->assertOk(),
            'schemas/resources/access.json#/$defs/StaffMemberResponse',
        );
        $this->assertMatchesContract(
            $request->patchJson('/api/v1/clinic/settings', ['document_settings' => ['pied_de_page' => 'Mention fictive']])->assertOk(),
            'schemas/resources/access.json#/$defs/ClinicSettingsResponse',
        );
    }

    public function test_les_erreurs_respectent_l_enveloppe_du_fichier_08(): void
    {
        $clinicId = $this->makeClinic();
        $manager = $this->makeStaff($clinicId, 'MANAGER', 'gerante.test');
        $cashier = $this->makeStaff($clinicId, 'CASHIER', 'caisse.test');
        $error = 'schemas/error.json';

        $this->assertMatchesContract($this->fromSpa()->getJson('/api/v1/auth/me')->assertStatus(401), $error);
        $this->assertMatchesContract($this->login('caisse.test', 'MauvaisMotDePasse99')->assertStatus(422), $error);
        $this->assertMatchesContract($this->actingAsFresh($cashier)->getJson('/api/v1/staff')->assertStatus(403), $error);
        $this->assertMatchesContract($this->actingAsFresh($manager)->postJson('/api/v1/staff', [])->assertStatus(423), $error);
        $request = $this->actingAsReauthenticated($manager);
        $this->assertMatchesContract($request->getJson('/api/v1/staff/01J9ZXK4M2Q8R7S6T5V4W3XZZZ')->assertStatus(404), $error);
        $this->assertMatchesContract($request->patchJson('/api/v1/staff/'.$cashier->id, ['expected_version' => 9, 'display_name' => 'Nom'])->assertStatus(409), $error);
        $this->assertMatchesContract($request->getJson('/api/v1/inexistant')->assertStatus(404), $error);
    }
}
