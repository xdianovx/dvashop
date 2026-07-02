@extends('layouts.app')

@section('title', $pageTitle ?? '2POROGA — кузовные пороги и арки')
@if (! empty($metaDescription))
    @section('meta_description', $metaDescription)
@endif

@section('content')
    <x-hero-circles />
    <x-search />
    <x-about />
@endsection
