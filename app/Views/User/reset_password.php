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
                <h1>Choose a new password</h1>

                <?php if ($invalid) { ?>
                    <div class="alert alert-danger py-2" role="alert">This reset link is invalid or has expired.</div>
                    <p class="lb-auth-links"><a href="<?= base_url('/user/forgot-password') ?>">Request a new link</a></p>
                <?php } else { ?>
                    <?php if (session()->getFlashdata('status')) { ?>
                        <div class="alert alert-danger py-2" role="alert"><?= esc(session()->getFlashdata('status')) ?></div>
                    <?php } ?>

                    <form action="<?= base_url('/user/reset') ?>" method="post">
                        <input type="hidden" name="token" value="<?= esc($token, 'attr') ?>">
                        <div class="form-floating mb-3">
                            <input type="password" class="form-control" id="password" name="password" placeholder="New password"
                                   minlength="8" autocomplete="new-password" required autofocus>
                            <label for="password">New password (8+ characters)</label>
                        </div>
                        <div class="form-floating mb-4">
                            <input type="password" class="form-control" id="repassword" name="repassword" placeholder="Repeat password"
                                   minlength="8" autocomplete="new-password" required>
                            <label for="repassword">Repeat new password</label>
                        </div>
                        <button class="btn btn-primary w-100 py-2 mb-3" type="submit">Change password</button>
                    </form>
                <?php } ?>
            </div>
        </main>
    </body>
</html>
