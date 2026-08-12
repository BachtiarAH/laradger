<?php

namespace App\Http\Controllers;

use App\Http\Requests\StorePocketRequest;
use App\Http\Requests\UpdatePocketRequest;
use App\Models\Pocket;

class PocketController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        //
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        //
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(StorePocketRequest $request)
    {
        //
    }

    /**
     * Display the specified resource.
     */
    public function show(Pocket $pocket)
    {
        //
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(Pocket $pocket)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdatePocketRequest $request, Pocket $pocket)
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Pocket $pocket)
    {
        //
    }
}
