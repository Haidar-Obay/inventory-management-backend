<?php

namespace App\Http\Controllers;

use OwenIt\Auditing\Models\Audit;

class AuditController extends Controller
{
    public function index()
    {
        $audits = Audit::latest()->get();

        return response()->json([
            'data' => $audits,
        ]);
    }
}
