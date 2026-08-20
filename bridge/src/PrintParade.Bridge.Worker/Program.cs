using System.ComponentModel;
using Microsoft.Extensions.DependencyInjection;
using Microsoft.Extensions.Hosting;
using PrintParade.Bridge.Core;
using PrintParade.Bridge.Windows;
using PrintParade.Bridge.Worker;

if (!OperatingSystem.IsWindows())
{
    Console.Error.WriteLine("The Print Parade bridge runs only on Windows.");
    return 1;
}

var configurationPath = ConfigurationPath(args);
var configurationStore = new WindowsBridgeConfigurationStore();
var command = Command(args);

if (string.Equals(command, "setup", StringComparison.OrdinalIgnoreCase))
{
    return await RunSetupAsync(configurationStore, configurationPath);
}

if (string.Equals(command, "uninstall", StringComparison.OrdinalIgnoreCase))
{
    return await RunUninstallAsync();
}

BridgeConfiguration configuration;

try
{
    configuration = await LoadConfigurationAsync(configurationStore, configurationPath);
}
catch (Exception exception) when (exception is IOException or UnauthorizedAccessException or Win32Exception)
{
    Console.Error.WriteLine($"Unable to load bridge configuration: {exception.Message}");
    Console.Error.WriteLine("Run 'PrintParadeBridge.exe setup' to configure this PC.");
    return 1;
}

if (string.Equals(command, "install", StringComparison.OrdinalIgnoreCase))
{
    return await RunInstallAsync();
}

var builder = Host.CreateApplicationBuilder();
builder.Services.AddWindowsService(options => options.ServiceName = WindowsServiceInstaller.DisplayName);
builder.Services.AddSingleton(configuration);
builder.Services.AddSingleton(new HttpClient { Timeout = TimeSpan.FromSeconds(30) });
builder.Services.AddSingleton(serviceProvider =>
{
    var httpClient = serviceProvider.GetRequiredService<HttpClient>();
    var settings = serviceProvider.GetRequiredService<BridgeConfiguration>();

    return new BridgeApiClient(httpClient, settings.ServerUrl, settings.BridgeToken);
});
builder.Services.AddSingleton<IPrintSpooler, WindowsRawPrintSpooler>();
builder.Services.AddSingleton<PrintJobProcessor>();
builder.Services.AddHostedService<BridgeBackgroundService>();

await builder.Build().RunAsync();
return 0;

static string? Command(string[] arguments)
{
    string[] commands = ["setup", "install", "uninstall", "run"];

    return arguments.FirstOrDefault(argument => commands.Contains(argument, StringComparer.OrdinalIgnoreCase));
}

static string? ConfigurationPath(string[] arguments)
{
    var optionIndex = Array.FindIndex(arguments, argument =>
        string.Equals(argument, "--config", StringComparison.OrdinalIgnoreCase));

    if (optionIndex < 0)
    {
        return null;
    }

    if (optionIndex + 1 >= arguments.Length || string.IsNullOrWhiteSpace(arguments[optionIndex + 1]))
    {
        throw new ArgumentException("The --config option requires a file path.");
    }

    return Path.GetFullPath(arguments[optionIndex + 1]);
}

static async Task<BridgeConfiguration> LoadConfigurationAsync(
    WindowsBridgeConfigurationStore store,
    string? path)
{
    var serverUrl = Environment.GetEnvironmentVariable("PRINT_PARADE_URL");
    var bridgeToken = Environment.GetEnvironmentVariable("PRINT_PARADE_BRIDGE_TOKEN");

    if (Uri.TryCreate(serverUrl, UriKind.Absolute, out var parsedServerUrl)
        && !string.IsNullOrWhiteSpace(bridgeToken))
    {
        return new BridgeConfiguration(parsedServerUrl, bridgeToken);
    }

    return await store.LoadAsync(path);
}

static async Task<int> RunSetupAsync(WindowsBridgeConfigurationStore store, string? path)
{
    Console.Write("Print Parade URL: ");
    var serverUrl = Console.ReadLine();

    if (!Uri.TryCreate(serverUrl, UriKind.Absolute, out var parsedServerUrl))
    {
        Console.Error.WriteLine("Enter a complete URL such as https://print.pacb.online.");
        return 1;
    }

    Console.Write("Bridge token: ");
    var bridgeToken = ReadSecret();

    if (string.IsNullOrWhiteSpace(bridgeToken))
    {
        Console.Error.WriteLine("The bridge token is required.");
        return 1;
    }

    var configuration = new BridgeConfiguration(parsedServerUrl, bridgeToken);

    try
    {
        using var httpClient = new HttpClient { Timeout = TimeSpan.FromSeconds(30) };
        var heartbeat = await new BridgeApiClient(httpClient, parsedServerUrl, bridgeToken).SendHeartbeatAsync();
        await store.SaveAsync(configuration, path);
        Console.WriteLine($"Bridge {heartbeat.BridgeId} connected successfully.");
        Console.WriteLine($"Configuration saved to {path ?? WindowsBridgeConfigurationStore.DefaultPath}.");

        return 0;
    }
    catch (Exception exception) when (exception is HttpRequestException or IOException or UnauthorizedAccessException or Win32Exception)
    {
        Console.Error.WriteLine($"Setup failed: {exception.Message}");
        return 1;
    }
}

static async Task<int> RunInstallAsync()
{
    try
    {
        var executablePath = Environment.ProcessPath
            ?? throw new InvalidOperationException("The bridge executable path could not be determined.");
        await WindowsServiceInstaller.InstallAsync(executablePath);
        Console.WriteLine("The Print Parade Bridge service was installed and started.");
        Console.WriteLine("Do not move or delete this executable while the service is installed.");

        return 0;
    }
    catch (Exception exception) when (exception is IOException or InvalidOperationException or Win32Exception)
    {
        Console.Error.WriteLine($"Service installation failed: {exception.Message}");
        Console.Error.WriteLine("Run this command from an Administrator terminal.");
        return 1;
    }
}

static async Task<int> RunUninstallAsync()
{
    try
    {
        await WindowsServiceInstaller.UninstallAsync();
        Console.WriteLine("The Print Parade Bridge service was removed.");

        return 0;
    }
    catch (Exception exception) when (exception is Win32Exception)
    {
        Console.Error.WriteLine($"Service removal failed: {exception.Message}");
        Console.Error.WriteLine("Run this command from an Administrator terminal.");
        return 1;
    }
}

static string ReadSecret()
{
    if (Console.IsInputRedirected)
    {
        return Console.ReadLine() ?? string.Empty;
    }

    var secret = new System.Text.StringBuilder();

    while (true)
    {
        var key = Console.ReadKey(intercept: true);

        if (key.Key == ConsoleKey.Enter)
        {
            Console.WriteLine();
            return secret.ToString();
        }

        if (key.Key == ConsoleKey.Backspace && secret.Length > 0)
        {
            secret.Length--;
            continue;
        }

        if (!char.IsControl(key.KeyChar))
        {
            secret.Append(key.KeyChar);
        }
    }
}
