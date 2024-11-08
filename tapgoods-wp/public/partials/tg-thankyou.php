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
    
    <script>
        // Verify and delete `cartData` from Local Storage. 
        if (localStorage.getItem('cartData')) {
            localStorage.removeItem('cartData');
        }
    </script>
</body>
</html>
