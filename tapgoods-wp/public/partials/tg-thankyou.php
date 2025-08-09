<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Thank You</title>
</head>
<body>
    <h1>Thank you!</h1>
    <p>Your order has been completed successfully.</p>
    
    <?php
// Script functionality moved to Tapgoods_Enqueue class and tapgoods-public-complete.js
// Inline script removed for WordPress best practices compliance
/*<script>
        // Verify and delete cart-related data from Local Storage
        const keysToRemove = [
            'cartData',
            'tg_eventStartDate',
            'tg_eventStartTime',
            'tg_eventEndDate',
            'tg_eventEndTime',
            'cart' // Cart status key
        ];

        keysToRemove.forEach((key) => {
            if (localStorage.getItem(key)) {
                localStorage.removeItem(key);
            }
        });

    </script>*/
?>
</body>
</html>
