document.addEventListener( 'wpcf7mailsent', function( event ) {
    let form = wpcf7_redirect_forms [ event.detail.contactFormId ];
    if (form.is_redirection) {
        location.href = form.thankyou_page_url;
    } else {
        return false
    }
    return false;
}, false );