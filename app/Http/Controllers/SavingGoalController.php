<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreSavingGoalRequest;
use App\Http\Requests\UpdateSavingGoalRequest;
use App\Models\SavingGoal;

class SavingGoalController extends Controller
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
    public function store(StoreSavingGoalRequest $request)
    {
        //
    }

    /**
     * Display the specified resource.
     */
    public function show(SavingGoal $savingGoal)
    {
        //
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(SavingGoal $savingGoal)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdateSavingGoalRequest $request, SavingGoal $savingGoal)
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(SavingGoal $savingGoal)
    {
        //
    }
}
