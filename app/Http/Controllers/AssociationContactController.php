<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreAssociationContactRequest;
use App\Http\Requests\UpdateAssociationContactRequest;
use App\Models\Association;
use App\Models\AssociationContact;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Maatwebsite\Excel\Facades\Excel;
use App\Exports\Export;
use App\Exports\ExportPDF;
use App\Imports\DynamicExcelImport;

class AssociationContactController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = AssociationContact::query()->with('association:id,name');
        if ($request->filled('association_id')) $query->where('association_id', $request->integer('association_id'));
        return response()->json($query->orderByDesc('id')->paginate());
    }

    public function store(StoreAssociationContactRequest $request): JsonResponse
    {
        $contact = AssociationContact::create($request->validated());
        return response()->json($contact->load('association:id,name'), 201);
    }

    public function show(AssociationContact $associationContact): JsonResponse
    {
        return response()->json($associationContact->load('association:id,name'));
    }

    public function update(UpdateAssociationContactRequest $request, AssociationContact $associationContact): JsonResponse
    {
        $associationContact->update($request->validated());
        return response()->json($associationContact->load('association:id,name'));
    }

    public function destroy(AssociationContact $associationContact): JsonResponse
    {
        $associationContact->delete();
        return response()->json(['message' => 'Deleted']);
    }

    public function byAssociation(Association $association): JsonResponse
    {
        $rows = AssociationContact::where('association_id', $association->id)->orderBy('contact_name')->get();
        return response()->json($rows);
    }

    public function bulkDelete(Request $request): JsonResponse
    {
        $request->validate(['ids' => ['required', 'array'], 'ids.*' => ['integer', 'exists:association_contacts,id']]);
        $skipped = [];
        $deleted = 0;
        foreach ($request->ids as $id) {
            try {
                $deleted += AssociationContact::where('id', $id)->delete();
            } catch (\Illuminate\Database\QueryException $e) {
                $skipped[] = ['id' => $id, 'reason' => $e->getMessage()];
            }
        }
        return response()->json(['message' => 'Bulk delete completed.', 'deleted_count' => $deleted, 'skipped' => $skipped]);
    }

    public function exportExcell()
    {
        $query = AssociationContact::query();
        $collection = $query->get();
        if ($collection->isEmpty()) return response()->json(['message' => 'No association contacts found.'], 404);
        $columns = ['id','association_id','contact_name','contact_phone','contact_email','created_at','updated_at'];
        $headings = ['ID','Association ID','Name','Phone','Email','Created At','Updated At'];
        $fileName = 'association_contacts_' . date('Y-m-d_H-i-s') . '.xlsx';
        return Excel::download(new Export($query, $columns, $headings), $fileName);
    }

    public function exportPdf(ExportPDF $pdfService)
    {
        $rows = AssociationContact::select('id','association_id','contact_name','contact_phone','contact_email')->get();
        if ($rows->isEmpty()) return response()->json(['message' => 'No association contacts found.'], 404);
        $title = 'Association Contacts';
        $headers = [
            'id' => 'ID',
            'association_id' => 'Association ID',
            'contact_name' => 'Name',
            'contact_phone' => 'Phone',
            'contact_email' => 'Email',
        ];
        $pdf = $pdfService->generatePdf($title, $headers, $rows->toArray());
        return $pdf->download('AssociationContacts.pdf');
    }

    public function importFromExcel(Request $request)
    {
        $request->validate(['file' => 'required|file|mimes:xlsx,xls,csv']);
        $import = new DynamicExcelImport(
            AssociationContact::class,
            ['association_id','contact_name','contact_phone','contact_email'],
            function ($row) {
                $errors = [];
                if (empty($row['association_id'])) $errors[] = 'Missing association_id';
                if (empty($row['contact_name'])) $errors[] = 'Missing contact_name';
                return $errors;
            },
            function ($row) {
                return [
                    'association_id' => (int) $row['association_id'],
                    'contact_name' => $row['contact_name'],
                    'contact_phone' => $row['contact_phone'] ?? null,
                    'contact_email' => $row['contact_email'] ?? null,
                ];
            }
        );
        Excel::import($import, $request->file('file'));
        return response()->json([
            'success' => true,
            'rows_imported' => $import->getImportedCount(),
            'rows_skipped_count' => $import->getSkippedCount(),
            'skipped_rows' => $import->getSkippedRows(),
        ]);
    }
}


