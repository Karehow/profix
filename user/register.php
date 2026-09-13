<?php

// Registration and borrower sign-in are intentionally kept in one secured flow.
header('Location: index.php?mode=register');
exit;
