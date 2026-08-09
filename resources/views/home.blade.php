@extends('layouts.app')

@section('content')
    @if ($pageData->hasSection(\App\Enums\HomepageSectionCode::QuickLinks->value) && $pageData->quickLinks !== [])
        <x-hero-circles :items="$pageData->quickLinks" />
    @endif

    @if ($pageData->hasSection(\App\Enums\HomepageSectionCode::VehicleSearch->value))
        <x-search :title="$pageData->sectionTitle(\App\Enums\HomepageSectionCode::VehicleSearch->value)" :makes="$pageData->vehicleMakes" />
    @endif

    @if ($pageData->hasSection(\App\Enums\HomepageSectionCode::CategoryCards->value) && $pageData->categoryCards !== [])
        <x-categories :cards="$pageData->categoryCards" />
    @endif

    @if ($pageData->hasSection(\App\Enums\HomepageSectionCode::AboutMetrics->value) && $pageData->metrics !== [])
        <x-about :title="$pageData->sectionTitle(\App\Enums\HomepageSectionCode::AboutMetrics->value)" :metrics="$pageData->metrics" />
    @endif
@endsection
