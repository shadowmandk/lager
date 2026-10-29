@extends('errors.layout')

@section('code', '419')
@section('title', 'Siden er udløbet')
@section('message', 'Siden har ligget åben længe. Hent den igen, så kan du fortsætte.')
@section('action')
    <a href="{{ url()->current() }}">Hent siden igen</a>
@endsection
