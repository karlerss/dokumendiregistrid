@extends('layout')

@section('title', 'Admin Login')

@section('content')
    <div class="container mx-auto px-4">
        <div class="max-w-md mx-auto mt-16">
            <x-bladewind.card>
                <x-slot:header>
                    <h2 class="text-2xl font-bold">Admin Login</h2>
                </x-slot:header>
                
                @if(session('error'))
                    <x-bladewind.alert type="error" :message="session('error')"></x-bladewind.alert>
                @endif
                
                <form action="{{ route('login') }}" method="POST">
                    @csrf
                    <x-bladewind.input 
                        name="token" 
                        type="password"
                        label="Admin Token"
                        required="true"
                        placeholder="Enter admin token"
                    />
                    
                    <div class="mt-4">
                        <x-bladewind.button 
                            can_submit="true"
                            class="w-full"
                        >
                            Login
                        </x-bladewind.button>
                    </div>
                </form>
            </x-bladewind.card>
        </div>
    </div>
@endsection

