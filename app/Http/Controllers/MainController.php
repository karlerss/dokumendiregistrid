<?php

namespace App\Http\Controllers;

use App\Lib\Summarizer;
use App\Models\Document;
use App\Models\File;
use App\Models\Organisation;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class MainController extends Controller
{

    public function index(Request $request)
    {
        if ($search = $request->get('query')) {
            $query = DB::table('fts_documents')
                ->join('documents', 'fts_documents.rowid', '=', 'documents.id')
                ->whereFullText('fts_documents', $search);
        } else {
            $query = DB::table('documents');
        }

        if ($request->org_ids) {
            $query->whereIn('organisation_id', explode(',', $request->org_ids));
        }

        if ($request->with_restricted == 1) {

        } else {
            $query->where('restriction', 'Avalik');
        }

        if ($start = $request->date_start) {
            $query->where('registration_date', '>=', $start);
        }

        if ($end = $request->date_end) {
            $query->where('registration_date', '<=', $end);
        }

        if ($minDelta = (int)$request->min_delta) {
            $query->whereRaw("julianday(created_at) - julianday(registration_date) >= $minDelta");
        }


        try {
            $count = $query->clone()->selectRaw("count(*) as count")->get()[0]->count;
            if ($request->sort_by === 'created_at') {
                $query->orderBy('created_at', 'desc');
            } else {
                $query->orderBy('registration_date', 'desc')
                    ->orderBy('documents.original_id', 'desc');
            }

            $ids = $query->offset((($request->page ?? 1) - 1) * 100)
                ->limit(100)
                ->orderBy('rank')
                ->pluck('id');

            $items = Document::query()->whereIn('id', $ids)
                ->with(['organisation'])
                ->get()
                ->keyBy('id')
                ->all();

            $items = array_replace(array_flip($ids->all()), $items);

            $documents = new LengthAwarePaginator(
                collect($items),
                $count,
                100,
                $request->page ?? 1
            );
        } catch (\Exception $e) {
            if (str_contains($e->getMessage(), 'fts5') || str_contains($e->getMessage(), 'column')) {
                return view('welcome', [
                    'documents' => Document::query()->whereRaw('1=0')->paginate(100),
                    'error' => 'Vigane otsisõna. Otsisõna lahter kasutab SQLite täistekstiotsingut. Kui otsid kindlat fraasi, pane oma otsisõna jutumärkidesse.'
                ]);
            }
        }
        return view('welcome', [
            'documents' => $documents,
        ]);
    }

    public function show(Document $document, ?string $slug = null)
    {
        if ($document->last_visibility === 'AK' || $document->last_visibility === 'Unknown') {
            if (!session('is_admin')) {
                abort(451);
            }
        }

        return view('document', [
            'document' => $document,
        ]);
    }

    public function about()
    {
        return view('about');
    }

    public function summarize(Document $document)
    {
        if (!session('is_admin')) {
            abort(403);
        }

        $text = $document->getContentsForSummary();

        if ($document->ai_summary || $document->ai_title) {
            throw new \Exception("Summaries already exist");
        }

        if (mb_strlen($text) > 16000) {
            throw new \Exception("Cannot summarize text longer than 16000 characters");
        }
        $summary = (new Summarizer())->summarize($text);

        $document->update([
            'ai_summary' => $summary['summary_content_html'],
            'ai_title' => $summary['title'],
        ]);

        return back();
    }

    public function dossier(string $slug)
    {
        $slug = urldecode($slug);
        $documents = Document::query()
            ->where('dossier', $slug)
            ->paginate(100);
        return view('dossier', [
            'documents' => $documents,
        ]);
    }

    public function archive()
    {
        $organisations = Organisation::query()
            ->withCount(['documents', 'restrictedDocuments'])->get();

        return view('archive.orgs', [
            'organisations' => $organisations,
        ]);
    }

    public function archiveOrg(string $orgSlug)
    {
        $org = Organisation::query()->where('slug', $orgSlug)->firstOrFail();

        $documentsPerYear = Document::query()
            ->where('organisation_id', $org->id)
            ->toBase()
            ->selectRaw("strftime('%Y', registration_date) as year, COUNT(*) as count, SUM(CASE WHEN  restriction='AK' THEN 1 ELSE 0 END) as ak_count")
            ->groupByRaw("strftime('%Y', registration_date)")
            ->orderByRaw("strftime('%Y', registration_date) desc")
            ->get();

        return view('archive.years', [
            'documentsPerYear' => $documentsPerYear,
            'organisation' => $org,
        ]);
    }

    public function archiveYear($orgSlug, string $year)
    {
        $org = Organisation::query()->where('slug', $orgSlug)->firstOrFail();

        $year = (int)$year;
        // documents per month
        $documentsPerMonth = Document::query()
            ->where('organisation_id', $org->id)
            ->whereRaw("strftime('%Y', registration_date) = '$year'")
            ->toBase()
            ->selectRaw("strftime('%m', registration_date) as month, COUNT(*) as count")
            ->groupByRaw("strftime('%m', registration_date)")
            ->orderByRaw("strftime('%m', registration_date) desc")
            ->get();

        return view('archive.months', [
            'documentsPerMonth' => $documentsPerMonth,
            'organisation' => $org,
            'year' => $year,
        ]);
    }

    public function archiveMonth($orgSlug, string $year, string $month)
    {
        $org = Organisation::query()->where('slug', $orgSlug)->firstOrFail();

        $year = (int)$year;
        $month = str_pad((int)$month, 2, '0', STR_PAD_LEFT);

        $documents = Document::query()
            ->where('organisation_id', $org->id)
            ->whereRaw("strftime('%Y', registration_date) = '$year'")
            ->whereRaw("strftime('%m', registration_date) = '$month'")
            ->orderBy('registration_date', 'desc')
            ->orderBy('original_id', 'desc')
            ->get();

        return view('archive.month', [
            'documents' => $documents,
            'organisation' => $org,
            'year' => $year,
            'month' => $month,
        ]);
    }

    public function login(Request $request)
    {
        // Show login form for GET requests
        if ($request->isMethod('get')) {
            return view('login');
        }

        // Handle login for POST requests
        $token = $request->input('token');
        $adminToken = env('ADMIN_TOKEN');

        if ($token && $adminToken && $token === $adminToken) {
            session(['is_admin' => true]);
            return redirect('/')->with('success', 'Successfully logged in as admin');
        }

        return back()->with('error', 'Invalid admin token');
    }

    public function logout()
    {
        session()->forget('is_admin');
        return redirect('/')->with('success', 'Successfully logged out');
    }

    public function destroy(Document $document)
    {
        if (!session('is_admin')) {
            abort(403);
        }

        $document->fullDelete();

        return back();
    }

    public function reindex(Document $document)
    {
        if (!session('is_admin')) {
            abort(403);
        }

        $document->reindex();

        return back();
    }

    public function deleteFile(File $file)
    {
        if (!session('is_admin')) {
            abort(403);
        }

        // Get the document before deleting the file
        $document = $file->document;

        if (!$document) {
            abort(404, 'File does not belong to any document');
        }

        // Delete the file (this will also delete from S3/R2 storage)
        $file->delete();

        // Reindex the document's FTS
        $document->ftsIndexSingle();

        return back()->with('success', 'File deleted successfully');
    }

    public function replaceFile(Request $request, File $file)
    {
        if (!session('is_admin')) {
            abort(403);
        }

        $request->validate([
            'file' => 'required|file|max:50000',
        ]);

        $document = $file->document;

        if (!$document) {
            abort(404, 'File does not belong to any document');
        }

        $uploaded = $request->file('file');
        $tempDir = storage_path('temp/' . \Illuminate\Support\Str::random(10));
        (new \Illuminate\Filesystem\Filesystem())->ensureDirectoryExists($tempDir);
        $tempPath = $tempDir . '/' . $uploaded->getClientOriginalName();
        $uploaded->move($tempDir, $uploaded->getClientOriginalName());

        $file->replaceWith($tempPath);

        (new \Illuminate\Filesystem\Filesystem())->deleteDirectory($tempDir);

        return back()->with('success', 'File replaced successfully');
    }

}
