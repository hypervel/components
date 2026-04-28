<x-mail::message subcopy="This is a subcopy">

<x-mail::table>
*Hi* {{ $user->name }}

| Hypervel      | Table         | Example       |
| ------------- | :-----------: | ------------: |
| Col 2 is      | Centered      | $10           |
| Col 3 is      | Right-Aligned | $20           |
</x-mail::table>

</x-mail::message>
