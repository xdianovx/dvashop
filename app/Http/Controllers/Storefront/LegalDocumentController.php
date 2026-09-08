<?php

namespace App\Http\Controllers\Storefront;

use App\Enums\LegalDocumentCode;
use App\Enums\LegalDocumentContentType;
use App\Http\Controllers\Controller;
use App\Models\LegalDocument;
use App\Services\Storefront\LegalDocumentViewDataProvider;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

final class LegalDocumentController extends Controller
{
    public function __construct(private readonly LegalDocumentViewDataProvider $documents) {}

    public function privacyPolicy(): View|RedirectResponse
    {
        return $this->show(LegalDocumentCode::PrivacyPolicy);
    }

    public function saleRules(): View|RedirectResponse
    {
        return $this->show(LegalDocumentCode::SaleRules);
    }

    public function returnsExchange(): View|RedirectResponse
    {
        return $this->show(LegalDocumentCode::ReturnsExchange);
    }

    public function informationUsageRules(): View|RedirectResponse
    {
        return $this->show(LegalDocumentCode::InformationUsageRules);
    }

    private function show(LegalDocumentCode $code): View|RedirectResponse
    {
        $document = LegalDocument::query()->where('code', $code->value)->published()->firstOrFail();
        if ($document->content_type === LegalDocumentContentType::Pdf) {
            $url = $document->publicDestination();
            abort_if($url === null, 404);

            return redirect()->away($url);
        }

        return view('legal-document', [
            'pageData' => $this->documents->load($code, $document),
        ]);
    }
}
