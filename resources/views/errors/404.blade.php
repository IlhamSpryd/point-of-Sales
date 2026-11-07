@extends('errors.layout')

@section('title', '404 Page Not Found')

@section('content')
    <div class="space-y-6">
        <div class="inline-flex items-center justify-center w-16 h-16 rounded-2xl bg-indigo-500/10 text-indigo-600 dark:text-indigo-400 border border-indigo-500/20">
            <span class="material-symbols-rounded text-3xl">search_off</span>
        </div>

        <div>
            <span class="text-xs font-sans font-semibold tracking-wider uppercase text-indigo-600 dark:text-indigo-400">Error 404 • Not Found</span>
            <h1 class="mt-2 text-2xl font-bold tracking-tight text-foreground sm:text-3xl">
                Page Not Found
            </h1>
            <p class="mt-3 text-sm text-muted-foreground leading-relaxed">
                {{ $exception->getMessage() ?: 'The requested page or resource could not be found, has been moved, or the link has expired.' }}
            </p>
        </div>

        <div class="flex flex-col sm:flex-row items-center justify-center gap-3 pt-2">
            <a href="{{ route('dashboard') }}" class="w-full sm:w-auto inline-flex items-center justify-center gap-2 px-4 py-2.5 rounded-lg bg-primary text-primary-foreground font-medium text-xs shadow-xs hover:opacity-90 transition-opacity focus:outline-hidden focus:ring-2 focus:ring-ring">
                <span class="material-symbols-rounded text-base">dashboard</span>
                <span>Return to Dashboard</span>
            </a>
            <button type="button" onclick="history.back()" class="w-full sm:w-auto inline-flex items-center justify-center gap-2 px-4 py-2.5 rounded-lg border border-border bg-card text-foreground font-medium text-xs hover:bg-muted/50 transition-colors focus:outline-hidden focus:ring-2 focus:ring-ring cursor-pointer">
                <span class="material-symbols-rounded text-base">arrow_back</span>
                <span>Previous Page</span>
            </button>
        </div>
    </div>
@endsection
