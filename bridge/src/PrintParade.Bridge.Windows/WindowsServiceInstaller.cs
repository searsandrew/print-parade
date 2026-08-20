using System.ComponentModel;
using System.Diagnostics;
using System.Runtime.Versioning;

namespace PrintParade.Bridge.Windows;

[SupportedOSPlatform("windows")]
public static class WindowsServiceInstaller
{
    public const string ServiceName = "PrintParadeBridge";
    public const string DisplayName = "Print Parade Bridge";

    public static async Task InstallAsync(string executablePath, CancellationToken cancellationToken = default)
    {
        EnsureWindows();
        var fullExecutablePath = Path.GetFullPath(executablePath);

        if (!File.Exists(fullExecutablePath))
        {
            throw new FileNotFoundException("The bridge executable could not be found.", fullExecutablePath);
        }

        await RunServiceControlAsync(
            ["create", ServiceName, "binPath=", $"\"{fullExecutablePath}\"", "start=", "delayed-auto", "DisplayName=", DisplayName],
            cancellationToken);
        await RunServiceControlAsync(
            ["description", ServiceName, "Receives approved Print Parade jobs and sends them to local label printers."],
            cancellationToken);
        await RunServiceControlAsync(
            ["failure", ServiceName, "reset=", "86400", "actions=", "restart/5000/restart/15000/restart/60000"],
            cancellationToken);
        await RunServiceControlAsync(["failureflag", ServiceName, "1"], cancellationToken);
        await RunServiceControlAsync(["start", ServiceName], cancellationToken);
    }

    public static async Task UninstallAsync(CancellationToken cancellationToken = default)
    {
        EnsureWindows();
        await RunServiceControlAsync(["stop", ServiceName], cancellationToken, allowFailure: true);
        await RunServiceControlAsync(["delete", ServiceName], cancellationToken);
    }

    private static async Task RunServiceControlAsync(
        IReadOnlyList<string> arguments,
        CancellationToken cancellationToken,
        bool allowFailure = false)
    {
        var startInfo = new ProcessStartInfo("sc.exe")
        {
            CreateNoWindow = true,
            RedirectStandardError = true,
            RedirectStandardOutput = true,
            UseShellExecute = false,
        };

        foreach (var argument in arguments)
        {
            startInfo.ArgumentList.Add(argument);
        }

        using var process = Process.Start(startInfo)
            ?? throw new InvalidOperationException("Windows Service Control Manager could not be started.");
        var outputTask = process.StandardOutput.ReadToEndAsync(cancellationToken);
        var errorTask = process.StandardError.ReadToEndAsync(cancellationToken);
        await process.WaitForExitAsync(cancellationToken);
        var output = await outputTask;
        var error = await errorTask;

        if (process.ExitCode != 0 && !allowFailure)
        {
            var detail = string.IsNullOrWhiteSpace(error) ? output : error;
            throw new Win32Exception(process.ExitCode, detail.Trim());
        }
    }

    private static void EnsureWindows()
    {
        if (!OperatingSystem.IsWindows())
        {
            throw new PlatformNotSupportedException("Windows services are available only on Windows.");
        }
    }
}
