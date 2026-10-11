<!DOCTYPE html>
<html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.0.2/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-EVSTQN3/azprG1Anm3QDgpJLIm9Nao0Yz1ztcQTwFspd3yD65VohhpuuCOmLASjC" crossorigin="anonymous">
        <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.0.2/dist/js/bootstrap.bundle.min.js" integrity="sha384-MrcW6ZMFYlzcLA8Nl+NtUVF0sA7MsXsP1UyJoMp4YLEuNSfAP+JcXn/tWtIaxVXM" crossorigin="anonymous"></script>
        <link rel="stylesheet" href="<?= base_url('assets/css/default.css') ?>">
        <link rel="stylesheet" href="<?= base_url('assets/css/theme.css') ?>">
        <title><?= esc($StoredText['ScreenTitle']) ?></title>
    </head>
    <body>
        <main class="lb-auth">
            <div class="lb-auth-card">
                <div class="lb-auth-brand">Liquidity Buddy</div>
                <h1>Log in to your account</h1>

                <?php if (session()->getFlashdata('status')) { ?>
                    <div class="alert alert-danger alert-dismissible fade show py-2" role="alert">
                        <?= esc(session()->getFlashdata('status')) ?>
                        <button type="button" class="btn-close py-2" data-bs-dismiss="alert" aria-label="Close"></button>
                    </div>
                <?php } ?>

                <form action="<?= base_url('/user/login-validation') ?>" method="post">
                    <div class="form-floating mb-3">
                        <input type="text" class="form-control" id="email" name="email" placeholder="Email or username"
                               value="<?= esc(old('email') ?? '', 'attr') ?>" autocomplete="username" required autofocus>
                        <label for="email">Email or username</label>
                    </div>
                    <div class="form-floating mb-4">
                        <input type="password" class="form-control" id="password" name="password" placeholder="Password"
                               minlength="8" autocomplete="current-password" required>
                        <label for="password">Password</label>
                    </div>
                    <button class="btn btn-primary w-100 py-2 mb-3" id="login-request" type="submit">Log in</button>
                    <p class="lb-auth-links">Forgot password? <a href="<?= base_url($StoredText['PassworResetLink']) ?>">Reset password</a></p>
                    <p class="lb-auth-links">New here? <a href="<?= base_url($StoredText['SignupLink']) ?>">Create an account</a></p>
                </form>
            </div>
        </main>
    </body>
</html>
