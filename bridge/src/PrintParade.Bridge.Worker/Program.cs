using System.ComponentModel;
using PrintParade.Bridge.Core;
using PrintParade.Bridge.Windows;

if (!OperatingSystem.IsWindows())
{
    Console.Error.WriteLine("The Print Parade bridge runs only on Windows.");
    return 1;
}

var configurationPath = ConfigurationPath(args);
var configurationStore = new WindowsBridgeConfigurationStore();

if (args.Contains("setup", StringComparer.OrdinalIgnoreCase))
{
    return await RunSetupAsync(configurationStore, configurationPath);
}

BridgeConfiguration configuration;

try
{
    configuration = await LoadConfigurationAsync(configurationStore, configurationPath);
}
catch (Exception exception) when (exception is IOException or UnauthorizedAccessException or Win32Exception)
{
    Console.Error.WriteLine($"Unable to load bridge configuration: {exception.Message}");
    Console.Error.WriteLine("Run 'PrintParade.Bridge.Worker.exe setup' to configure this PC.");
    return 1;
}

using var shutdown = new CancellationTokenSource();
Console.CancelKeyPress += (_, eventArgs) =>
{
    eventArgs.Cancel = true;
    shutdown.Cancel();
};

using var httpClient = new HttpClient { Timeout = TimeSpan.FromSeconds(30) };
var bridgeClient = new BridgeApiClient(httpClient, configuration.ServerUrl, configuration.BridgeToken);
var processor = new PrintJobProcessor(bridgeClient, new WindowsRawPrintSpooler());

Console.WriteLine("Starting the Print Parade bridge. Press Ctrl+C to stop.");

while (!shutdown.IsCancellationRequested)
{
    try
    {
        var heartbeat = await bridgeClient.SendHeartbeatAsync(shutdown.Token);
        var processedJob = await processor.ProcessNextAsync(shutdown.Token);

        if (processedJob)
        {
            Console.WriteLine($"Bridge {heartbeat.BridgeId} processed a print job.");
        }

        await Task.Delay(TimeSpan.FromSeconds(processedJob ? 0 : 2), shutdown.Token);
    }
    catch (OperationCanceledException) when (shutdown.IsCancellationRequested)
    {
        break;
    }
    catch (Exception exception) when (exception is HttpRequestException or BridgeProtocolException)
    {
        Console.Error.WriteLine($"Print Parade is unavailable: {exception.Message}");
        await DelayAfterFailureAsync(shutdown.Token);
    }
}

Console.WriteLine("Print Parade bridge stopped.");
return 0;

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

static async Task DelayAfterFailureAsync(CancellationToken cancellationToken)
{
    try
    {
        await Task.Delay(TimeSpan.FromSeconds(5), cancellationToken);
    }
    catch (OperationCanceledException) when (cancellationToken.IsCancellationRequested)
    {
    }
}
