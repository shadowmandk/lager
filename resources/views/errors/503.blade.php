@extends('errors.layout')

@section('code', '503')
@section('title', 'Systemet opdateres')
@section('message', 'Der lægges en ny version på. Det tager normalt under et minut.')
@section('action')
    <a href="{{ url()->current() }}">Prøv igen</a>
@endsection
