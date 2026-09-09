/* 
========================================================================
   BOOTSTRAP 5 ADMIN TEMPLATE - SPARK ADMIN
   DOCUMENTATION INTERACTION JAVASCRIPT
   Developed with premium UI/UX standards

   Template Name: Spark Admin
   Version: 1.0 
   Author: Spark Admin Team 
   Email: hello.sparkadmin@gmail.com
   URL: https://sparkadmin.web.id
========================================================================
*/

/**
 * Copy Code Snippet Handler
 * Copies the innerText of a <code> block within a <pre> element to clipboard.
 * 
 * @param {HTMLElement} button - The button element triggered by user click
 */
function copyCode(button) {
    if (!button) return;
    const pre = button.parentElement;
    if (!pre) return;
    const code = pre.querySelector('code');
    if (!code) return;
    const text = code.innerText;

    navigator.clipboard.writeText(text).then(() => {
        const originalText = button.innerText;
        button.innerText = 'Copied!';
        button.classList.add('copied');
        
        setTimeout(() => {
            button.innerText = originalText;
            button.classList.remove('copied');
        }, 2000);
    }).catch((err) => {
        console.error('Failed to copy code snippet: ', err);
    });
}
