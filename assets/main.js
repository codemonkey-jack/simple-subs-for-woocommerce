jQuery(function ($) {
    $('body').on('click', '#_wps_subscription', function () {
        if ($(this).prop('checked') == true) {
            $('.wpsatchel-product').removeClass('satchel-hide');
        } else {
            $('.wpsatchel-product').addClass('satchel-hide');
        }
    })
    var $form = $('form.checkout');
    var is_good = null;
    $form.on('checkout_place_order', function () {
        if ($('input[name="payment_method"]:checked').val() == 'wpsatchel-stripe') {
            if ($('.card_form').is(':visible') && $form.find('input[name="stripeToken"]').size() == 0) {
                var res = Stripe.card.createToken($form, stripeResponseHandler);
                return false;
            } else {
                return true;
            }
        }
    });
    $('body').on('change', 'input[name="satchel_stripe_card_list"]', function () {
        if ($(this).val() == 'create_new_card') {
            $('.card_form').removeClass('satchel-hide');
        } else {
            $('.card_form').addClass('satchel-hide');
        }
    })

    function stripeResponseHandler(status, response) {
        // Grab the form:
        if (response.error) { // Problem!
            is_good = false;
            // Show the errors on the form:
            console.log(response.error.message);
            return false;

        } else { // Token was created!
            is_good = true;
            // Get the token ID:
            var token = response.id;

            // Insert the token ID into the form so it gets submitted to the server:
            $form.append($('<input type="hidden" name="stripeToken">').val(token));

            // Submit the form:
            $form.submit();
            return true;
        }
    };
})