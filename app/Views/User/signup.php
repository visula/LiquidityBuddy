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
                <h1>Create your account</h1>

                <?php if (session()->getFlashdata('status')) { ?>
                    <div class="alert alert-danger alert-dismissible fade show py-2" role="alert">
                        <?= esc(session()->getFlashdata('status')) ?>
                        <button type="button" class="btn-close py-2" data-bs-dismiss="alert" aria-label="Close"></button>
                    </div>
                <?php } ?>

                <form action="<?= base_url('/user/signup-validation') ?>" method="post">
                    <div class="row g-2">
                        <div class="col-6">
                            <div class="form-floating mb-3">
                                <input type="text" class="form-control" id="fname" name="fname" placeholder="First name" value="<?= esc(old('fname') ?? '', 'attr') ?>" maxlength="50" required>
                                <label for="fname">First name</label>
                            </div>
                        </div>
                        <div class="col-6">
                            <div class="form-floating mb-3">
                                <input type="text" class="form-control" id="lname" name="lname" placeholder="Last name" value="<?= esc(old('lname') ?? '', 'attr') ?>" maxlength="50" required>
                                <label for="lname">Last name</label>
                            </div>
                        </div>
                    </div>
                    <div class="form-floating mb-3">
                        <input type="text" class="form-control" id="username" name="username" placeholder="Username" value="<?= esc(old('username') ?? '', 'attr') ?>"
                               pattern="[A-Za-z0-9._\-]{3,50}" title="3-50 letters, digits, dots, dashes or underscores" autocomplete="username">
                        <label for="username">Username</label>
                    </div>
                    <div class="form-floating mb-1">
                        <input type="email" class="form-control" id="email" name="email" placeholder="Email" value="<?= esc(old('email') ?? '', 'attr') ?>" maxlength="50" autocomplete="email">
                        <label for="email">Email</label>
                    </div>
                    <p class="text-muted small mb-3">A username, an email or both. You can log in with either.</p>
                    <div class="form-floating mb-3">
                        <input type="password" class="form-control" id="password" name="password" placeholder="Password" minlength="8" autocomplete="new-password" required>
                        <label for="password">Password (8+ characters)</label>
                    </div>
                    <div class="form-floating mb-4">
                        <input type="password" class="form-control" id="repassword" name="repassword" placeholder="Repeat password" minlength="8" autocomplete="new-password" required>
                        <label for="repassword">Repeat password</label>
                    </div>
                    <button class="btn btn-primary w-100 py-2 mb-3" id="signup-request" type="submit">Create account</button>
                    <p class="lb-auth-links">Already have an account? <a href="<?= base_url('/login') ?>">Log in</a></p>
                </form>
            </div>
        </main>
    </body>
</html>
