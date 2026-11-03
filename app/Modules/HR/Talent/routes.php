<?php

use App\Modules\HR\SelfService\SelfServiceController as M;
use App\Modules\HR\Talent\Http\TalentController as T;
use Illuminate\Support\Facades\Route;

// H-B talent and compliance. Included from app/Modules/HR/routes.php; permissions checked in the controller.
Route::prefix('hr/talent')->controller(T::class)->group(function () {
    Route::get('posts', 'posts');
    Route::post('posts', 'savePost');
    Route::patch('posts/{post}', 'savePost');
    Route::post('posts/{post}/status', 'postStatus');
    Route::get('applicants', 'applicants');
    Route::post('applicants', 'addApplicant');
    Route::post('applicants/purge-expired', 'purge');
    Route::get('applicants/{applicant}', 'showApplicant');
    Route::post('applicants/{applicant}/stage', 'stage');
    Route::post('applicants/{applicant}/notes', 'note');
    Route::post('applicants/{applicant}/pool', 'pool');
    Route::get('applicants/{applicant}/cv', 'cv');
    Route::post('applicants/{applicant}/anonymise', 'anonymise');
    Route::post('applicants/{applicant}/interviews', 'schedule');
    Route::post('applicants/{applicant}/offers', 'createOffer');
    Route::post('interviews/{interview}/scores', 'score');
    Route::get('interviews/{interview}/scorecard', 'scorecard');
    Route::get('offers', 'offers');
    Route::post('offers/{offer}/send', 'sendOffer');
    Route::post('offers/{offer}/withdraw', 'withdrawOffer');
    Route::post('offers/{offer}/accept', 'acceptOffer');

    Route::get('catalogue', 'skillList');
    Route::post('skills', 'saveSkill');
    Route::post('cert-types', 'saveCertType');
    Route::post('position-skills', 'positionSkill');
    Route::post('employees/{employee}/skills', 'employeeSkill');
    Route::get('matrix', 'matrix');
    Route::get('gaps', 'gaps');
    Route::get('certifications', 'certifications');
    Route::post('employees/{employee}/certifications', 'addCertification');
    Route::post('certifications/scan', 'scanCertifications');

    Route::get('courses', 'courses');
    Route::post('courses', 'saveCourse');
    Route::get('sessions', 'sessions');
    Route::post('sessions', 'createSession');
    Route::post('sessions/{session}/enroll', 'enroll');
    Route::post('sessions/{session}/record', 'record');
    Route::post('sessions/{session}/complete', 'completeSession');
    Route::post('sessions/{session}/cancel', 'cancelSession');
    Route::get('employees/{employee}/training', 'trainingRecords');
    Route::get('training-cost', 'trainingCost');

    Route::get('headcount', 'headcount');
    Route::put('headcount', 'saveBudget');
    Route::get('succession', 'succession');
    Route::post('key-positions', 'keyPosition');
    Route::post('successors', 'successor');
    Route::delete('successors/{successor}', 'removeSuccessor');

    Route::get('requests', 'requests');
    Route::post('requests/{req}/decide', 'decideRequest');
    Route::get('payroll/{run}/checks', 'payrollChecks');
    Route::post('payroll/{run}/acknowledge', 'acknowledge');
    Route::get('settings', 'settings');
    Route::put('settings', 'saveSettings');

    Route::get('privacy/eligible', 'privacyEligible');
    Route::get('privacy/employees/{employee}/export', 'export');
    Route::post('privacy/employees/{employee}/erase', 'erase');
});

// Employee self-service (/me): identity is the linked employee record of the signed-in user.
Route::prefix('hr/me')->controller(M::class)->group(function () {
    Route::get('/', 'profile');
    Route::get('leave', 'leave');
    Route::post('leave', 'requestLeave');
    Route::get('schedule', 'schedule');
    Route::get('documents', 'documents');
    Route::get('documents/{document}/download', 'downloadDocument');
    Route::get('contracts', 'contracts');
    Route::post('contracts/{contract}/link', 'contractLink');
    Route::get('requests', 'requests');
    Route::post('requests', 'storeRequest');
    Route::get('requests/{req}/certificate', 'certificate');
});
