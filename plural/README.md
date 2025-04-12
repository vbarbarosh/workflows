# Handle pluralization of words or phrases

Display count-dependent text with proper grammatical number agreement in English language.

```javascript
function plural(n, singular, plural, zero)
{
    if (n === 0 && typeof zero === 'string') {
        return zero;
    }
    return (n % 10 === 1 && n % 100 !== 11)
        ? singular.split('#').join(n)
        : plural.split('#').join(n);
}
```

Some examples:

```javascript
plural(1, 'There is # cat', 'There are # cats') 
// → 'There is 1 cat'

plural(5, 'There is # cat', 'There are # cats') 
// → 'There are 5 cats'

plural(0, 'There is # cat', 'There are # cats') 
// → 'There are 0 cats

plural(0, 'There is # cat', 'There are # cats', 'No cats') 
// → 'No cats'

plural(21, 'There is # cat', 'There are # cats') 
// → 'There is 21 cat'
```
