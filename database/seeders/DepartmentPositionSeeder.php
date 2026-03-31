<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Organization;
use App\Models\User;
use App\Models\Department;
use App\Models\Position;

class DepartmentPositionSeeder extends Seeder
{
    public function run(): void
    {
        if (Department::count() > 0) {
            $this->command->info('Departments already seeded. Skipping to avoid duplicates.');
            return;
        }
        // Get organizations
        $org = Organization::where('slug', 'pt-tester-indonesia')->first();
        $org2 = Organization::where('slug', 'pt-sainskerta-solusi-nusantara')->first();

        // Get users for org 1
        $admin = User::where('email', 'pendtiumpraz@gmail.com')->first();
        $dpo = User::where('email', 'budi.dpo@tester.co.id')->first();
        $maker = User::where('email', 'andi.maker@tester.co.id')->first();
        $viewer = User::where('email', 'sari.viewer@tester.co.id')->first();

        // Get users for org 2
        $ssn_admin = User::where('email', 'galih.admin@sainskerta.co.id')->first();
        $ssn_dpo = User::where('email', 'diana.dpo@sainskerta.co.id')->first();
        $ssn_maker = User::where('email', 'rina.maker@sainskerta.co.id')->first();
        $ssn_viewer = User::where('email', 'joni.viewer@sainskerta.co.id')->first();

        if ($org && $admin) {
            // =============================================
            // Departments & Positions — PT Tester Indonesia
            // =============================================
            $dept_it = Department::create(['org_id' => $org->id, 'name' => 'Information Technology', 'code' => 'IT', 'description' => 'Divisi teknologi informasi dan keamanan siber', 'is_active' => true]);
            $dept_hr = Department::create(['org_id' => $org->id, 'name' => 'Human Capital', 'code' => 'HC', 'description' => 'Divisi sumber daya manusia', 'is_active' => true]);
            $dept_legal = Department::create(['org_id' => $org->id, 'name' => 'Legal & Compliance', 'code' => 'LEGAL', 'description' => 'Divisi hukum dan kepatuhan regulasi', 'is_active' => true]);
            $dept_finance = Department::create(['org_id' => $org->id, 'name' => 'Finance & Accounting', 'code' => 'FIN', 'description' => 'Divisi keuangan dan akuntansi', 'is_active' => true]);
            $dept_ops = Department::create(['org_id' => $org->id, 'name' => 'Operations', 'code' => 'OPS', 'description' => 'Divisi operasional bisnis', 'is_active' => true]);

            // Sub-departments
            Department::create(['org_id' => $org->id, 'name' => 'Cybersecurity', 'code' => 'CYBSEC', 'parent_id' => $dept_it->id, 'description' => 'Tim keamanan siber', 'is_active' => true]);
            Department::create(['org_id' => $org->id, 'name' => 'Infrastructure', 'code' => 'INFRA', 'parent_id' => $dept_it->id, 'description' => 'Tim infrastruktur dan cloud', 'is_active' => true]);

            $pos_dpo = Position::create(['org_id' => $org->id, 'name' => 'Data Protection Officer', 'department_id' => $dept_legal->id, 'level' => 'Manager', 'description' => 'Pejabat pelindungan data pribadi sesuai UU PDP', 'is_active' => true]);
            $pos_ciso = Position::create(['org_id' => $org->id, 'name' => 'Chief Information Security Officer', 'department_id' => $dept_it->id, 'level' => 'C-Level', 'description' => 'Penanggung jawab keamanan informasi', 'is_active' => true]);
            $pos_itm = Position::create(['org_id' => $org->id, 'name' => 'IT Manager', 'department_id' => $dept_it->id, 'level' => 'Manager', 'description' => 'Manajer teknologi informasi', 'is_active' => true]);
            $pos_auditor = Position::create(['org_id' => $org->id, 'name' => 'Compliance Auditor', 'department_id' => $dept_legal->id, 'level' => 'Staff', 'description' => 'Auditor kepatuhan regulasi', 'is_active' => true]);
            Position::create(['org_id' => $org->id, 'name' => 'HR Manager', 'department_id' => $dept_hr->id, 'level' => 'Manager', 'description' => 'Manajer sumber daya manusia', 'is_active' => true]);
            Position::create(['org_id' => $org->id, 'name' => 'Finance Manager', 'department_id' => $dept_finance->id, 'level' => 'Manager', 'description' => 'Manajer keuangan', 'is_active' => true]);
            Position::create(['org_id' => $org->id, 'name' => 'Security Analyst', 'department_id' => $dept_it->id, 'level' => 'Staff', 'description' => 'Analis keamanan siber', 'is_active' => true]);
            Position::create(['org_id' => $org->id, 'name' => 'Legal Counsel', 'department_id' => $dept_legal->id, 'level' => 'Staff', 'description' => 'Penasihat hukum', 'is_active' => true]);

            // Assign dept/position to users
            if ($admin) $admin->update(['department_id' => $dept_it->id, 'position_id' => $pos_itm->id]);
            if ($dpo) $dpo->update(['department_id' => $dept_legal->id, 'position_id' => $pos_dpo->id]);
            if ($maker) $maker->update(['department_id' => $dept_it->id, 'position_id' => $pos_ciso->id]);
            if ($viewer) $viewer->update(['department_id' => $dept_legal->id, 'position_id' => $pos_auditor->id]);

            // Set dept heads
            if ($admin) $dept_it->update(['head_user_id' => $admin->id]);
            if ($dpo) $dept_legal->update(['head_user_id' => $dpo->id]);
        }

        if ($org2 && $ssn_admin) {
            // =============================================
            // Departments & Positions — PT Sainskerta
            // =============================================
            $ssn_dept_it = Department::create(['org_id' => $org2->id, 'name' => 'Technology & Engineering', 'code' => 'TECH', 'description' => 'Divisi teknologi dan engineering', 'is_active' => true]);
            $ssn_dept_risk = Department::create(['org_id' => $org2->id, 'name' => 'Risk & Compliance', 'code' => 'RISK', 'description' => 'Divisi risiko dan kepatuhan', 'is_active' => true]);
            $ssn_dept_ops = Department::create(['org_id' => $org2->id, 'name' => 'Business Operations', 'code' => 'BIZOPS', 'description' => 'Divisi operasional bisnis', 'is_active' => true]);
            $ssn_dept_fin = Department::create(['org_id' => $org2->id, 'name' => 'Finance', 'code' => 'FIN', 'description' => 'Divisi keuangan', 'is_active' => true]);

            $ssn_pos_cto = Position::create(['org_id' => $org2->id, 'name' => 'Chief Technology Officer', 'department_id' => $ssn_dept_it->id, 'level' => 'C-Level', 'description' => 'Penanggung jawab teknologi', 'is_active' => true]);
            $ssn_pos_dpo = Position::create(['org_id' => $org2->id, 'name' => 'Data Protection Officer', 'department_id' => $ssn_dept_risk->id, 'level' => 'Manager', 'description' => 'Pejabat PDP', 'is_active' => true]);
            $ssn_pos_rcm = Position::create(['org_id' => $org2->id, 'name' => 'Risk & Compliance Manager', 'department_id' => $ssn_dept_risk->id, 'level' => 'Manager', 'description' => 'Manajer risiko dan kepatuhan', 'is_active' => true]);
            $ssn_pos_aud = Position::create(['org_id' => $org2->id, 'name' => 'Internal Auditor', 'department_id' => $ssn_dept_risk->id, 'level' => 'Staff', 'description' => 'Auditor internal', 'is_active' => true]);
            Position::create(['org_id' => $org2->id, 'name' => 'Backend Engineer', 'department_id' => $ssn_dept_it->id, 'level' => 'Staff', 'description' => 'Software engineer backend', 'is_active' => true]);
            Position::create(['org_id' => $org2->id, 'name' => 'DevOps Engineer', 'department_id' => $ssn_dept_it->id, 'level' => 'Staff', 'description' => 'DevOps & infrastructure', 'is_active' => true]);

            if ($ssn_admin) $ssn_admin->update(['department_id' => $ssn_dept_it->id, 'position_id' => $ssn_pos_cto->id]);
            if ($ssn_dpo) $ssn_dpo->update(['department_id' => $ssn_dept_risk->id, 'position_id' => $ssn_pos_dpo->id]);
            if ($ssn_maker) $ssn_maker->update(['department_id' => $ssn_dept_risk->id, 'position_id' => $ssn_pos_rcm->id]);
            if ($ssn_viewer) $ssn_viewer->update(['department_id' => $ssn_dept_risk->id, 'position_id' => $ssn_pos_aud->id]);

            if ($ssn_admin) $ssn_dept_it->update(['head_user_id' => $ssn_admin->id]);
            if ($ssn_dpo) $ssn_dept_risk->update(['head_user_id' => $ssn_dpo->id]);
        }
    }
}
