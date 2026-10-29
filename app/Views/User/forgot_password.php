<!DOCTYPE html>
<html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.0.2/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-EVSTQN3/azprG1Anm3QDgpJLIm9Nao0Yz1ztcQTwFspd3yD65VohhpuuCOmLASjC" crossorigin="anonymous">
        <link rel="stylesheet" href="<?= base_url('assets/css/default.css') ?>">
        <link rel="stylesheet" href="<?= base_url('assets/css/theme.css') ?>">
        <title><?= esc($StoredText['ScreenTitle']) ?></title>
    </head>
    <body>
        <main class="lb-auth">
            <div class="lb-auth-card">
                <div class="lb-auth-brand">Liquidity Buddy</div>
                <h1>Reset your password</h1>

                <?php if (session()->getFlashdata('status')) { ?>
                    <div class="alert alert-danger alert-dismissible fade show py-2" role="alert">
                        <?= esc(session()->getFlashdata('status')) ?>
                        <button type="button" class="btn-close py-2" data-bs-dismiss="alert" aria-label="Close"></button>
                    </div>
                <?php } ?>
                <?php if (session()->getFlashdata('success')) { ?>
                    <div class="alert alert-success py-2" role="status"><?= esc(session()->getFlashdata('success')) ?></div>
                <?php } ?>

                <form action="<?= base_url('/user/forgot-password') ?>" method="post">
                    <div class="form-floating mb-4">
                        <input type="text" class="form-control" id="login" name="login" placeholder="Email or username"
                               autocomplete="username" required autofocus>
                        <label for="login">Email or username</label>
                    </div>
                    <button class="btn btn-primary w-100 py-2 mb-3" type="submit">Send reset link</button>
                    <p class="lb-auth-links"><a href="<?= base_url('/login') ?>">Back to log in</a></p>
                </form>
            </div>
        </main>
    </body>
</html>
